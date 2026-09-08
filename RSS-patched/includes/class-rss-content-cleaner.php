<?php
/**
 * Strips boilerplate blocks out of imported feed content.
 *
 * @package           RSSFeedManager
 * @author            Senior WordPress Developer
 * @copyright         2026 RSS Feed Manager
 * @license           GPL-2.0-or-later
 */

namespace RSSFeedManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ContentCleaner
 *
 * Removes trailing reference/citation blocks that publishers append to feed items,
 * such as the "Source Reference Map" list of per-paragraph citations, and optionally
 * the "Source: <name>" credit line that follows it.
 */
class ContentCleaner {

	/**
	 * Longest a line may be and still be treated as a credit line rather than prose.
	 */
	const CREDIT_MAX_LENGTH = 200;

	/**
	 * Headings that begin a block to remove.
	 *
	 * @return string[] Lower-case, whitespace-normalised labels.
	 */
	public static function get_markers() {
		$markers = apply_filters(
			'rss_feed_manager_strip_blocks',
			[
				'source reference map',
			]
		);

		return array_values(
			array_filter(
				array_map(
					static function ( $marker ) {
						return self::normalise( $marker );
					},
					(array) $markers
				)
			)
		);
	}

	/**
	 * Pattern identifying a publisher credit line.
	 *
	 * @return string A regular expression.
	 */
	public static function get_credit_pattern() {
		return (string) apply_filters(
			'rss_feed_manager_credit_pattern',
			'/^(source|sources|credit|credits|attribution|courtesy|via)\s*[:\-–—]\s*\S/i'
		);
	}

	/**
	 * Normalise a string for comparison.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private static function normalise( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		// Normalise non-breaking spaces as well as ordinary whitespace.
		$text = preg_replace( '/[\s\x{00A0}]+/u', ' ', $text );

		return strtolower( trim( (string) $text ) );
	}

	/**
	 * Plugin settings, read once.
	 *
	 * @return array
	 */
	private static function get_settings() {
		$settings = get_option( 'rss_feed_manager_settings', [] );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * Whether reference-block stripping is switched on.
	 *
	 * @param array|null $settings Optional settings array.
	 * @return bool
	 */
	public static function is_enabled( $settings = null ) {
		$settings = null === $settings ? self::get_settings() : (array) $settings;

		// Default on, so installs upgrading from an older version get the cleanup too.
		$enabled = ! isset( $settings['strip_source_map'] ) || '1' === $settings['strip_source_map'];

		return (bool) apply_filters( 'rss_feed_manager_strip_enabled', $enabled );
	}

	/**
	 * Whether the publisher credit line should be removed as well.
	 *
	 * @param array|null $settings Optional settings array.
	 * @return bool
	 */
	public static function credit_enabled( $settings = null ) {
		$settings = null === $settings ? self::get_settings() : (array) $settings;

		$enabled = ! isset( $settings['strip_source_credit'] ) || '1' === $settings['strip_source_credit'];

		return (bool) apply_filters( 'rss_feed_manager_strip_credit_enabled', $enabled );
	}

	/**
	 * Whether a piece of content still contains something strippable.
	 *
	 * @param string    $html          Post content.
	 * @param bool|null $remove_credit Include credit lines in the check.
	 * @return bool
	 */
	public static function has_block( $html, $remove_credit = null ) {
		$remove_credit = null === $remove_credit ? self::credit_enabled() : (bool) $remove_credit;
		$haystack      = self::normalise( $html );

		foreach ( self::get_markers() as $marker ) {
			if ( '' !== $marker && false !== strpos( $haystack, $marker ) ) {
				return true;
			}
		}

		if ( $remove_credit && preg_match( '/(source|sources|credit|credits|attribution|courtesy|via)\s*[:\-–—]\s*\S/i', $haystack ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Apply stripping according to the plugin settings.
	 *
	 * @param string     $html     Post content.
	 * @param array|null $settings Plugin settings.
	 * @return string
	 */
	public static function maybe_strip( $html, $settings = null ) {
		if ( ! self::is_enabled( $settings ) ) {
			return $html;
		}

		return self::strip( $html, self::credit_enabled( $settings ) );
	}

	/**
	 * Remove the reference block, and optionally the credit line, from a content string.
	 *
	 * @param string    $html          Post content.
	 * @param bool|null $remove_credit Also remove "Source: <name>" lines.
	 * @return string Cleaned content.
	 */
	public static function strip( $html, $remove_credit = null ) {
		$html          = (string) $html;
		$remove_credit = null === $remove_credit ? self::credit_enabled() : (bool) $remove_credit;

		if ( '' === trim( $html ) || ! self::has_block( $html, $remove_credit ) ) {
			return $html;
		}

		$original_text = self::normalise( $html );
		$result        = null;

		if ( class_exists( '\DOMDocument' ) ) {
			$result = self::strip_with_dom( $html, $remove_credit );
		}

		if ( null === $result ) {
			$result = self::strip_with_string( $html, $remove_credit );
		}

		if ( $remove_credit ) {
			// Catch credits appended to the end of a paragraph rather than given
			// their own element, which the node-level passes cannot see.
			$result = self::strip_trailing_credit( $result );
			$result = self::remove_empty_blocks( $result );
		}

		/*
		 * Safety net: never hand back an empty body for an item that had text. Better to
		 * leave the boilerplate in place than to publish a blank post.
		 */
		if ( '' !== $original_text && '' === self::normalise( $result ) ) {
			return $html;
		}

		return $result;
	}

	/**
	 * DOM-based removal.
	 *
	 * @param string $html          Post content.
	 * @param bool   $remove_credit Also remove credit lines.
	 * @return string|null Cleaned content, or null when parsing failed.
	 */
	private static function strip_with_dom( $html, $remove_credit ) {
		$dom = new \DOMDocument();

		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadHTML( '<?xml encoding="utf-8" ?><html><body>' . $html . '</body></html>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded ) {
			return null;
		}

		$body = $dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			return null;
		}

		$markers = self::get_markers();
		$removed = false;

		// Phase 1: the marker block and everything trailing it.
		$guard = 0;
		while ( $guard++ < 5 ) {
			$marker_node = self::find_marker_node( $dom, $markers );
			if ( ! $marker_node ) {
				break;
			}

			$node = $marker_node;
			while ( $node ) {
				$next = $node->nextSibling;

				// With credit removal off, stop before the credit line and keep it.
				if ( ! $remove_credit && $node !== $marker_node && XML_ELEMENT_NODE === $node->nodeType ) {
					if ( self::is_credit_text( self::normalise( $node->textContent ) ) ) {
						break;
					}
				}

				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
					$removed = true;
				}

				$node = $next;
			}
		}

		// Phase 2: standalone credit lines anywhere in the content.
		if ( $remove_credit ) {
			$targets = [];

			foreach ( $dom->getElementsByTagName( '*' ) as $element ) {
				$text = self::normalise( $element->textContent );

				if ( ! self::is_credit_text( $text ) ) {
					continue;
				}

				// Climb to the outermost node that contains nothing but this line.
				$node = $element;
				while (
					$node->parentNode
					&& XML_ELEMENT_NODE === $node->parentNode->nodeType
					&& ! in_array( strtolower( $node->parentNode->nodeName ), [ 'body', 'html' ], true )
					&& self::normalise( $node->parentNode->textContent ) === $text
				) {
					$node = $node->parentNode;
				}

				$targets[ spl_object_id( $node ) ] = $node;
			}

			foreach ( $targets as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
					$removed = true;
				}
			}
		}

		if ( ! $removed ) {
			return null;
		}

		$out = '';
		foreach ( $body->childNodes as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return trim( $out );
	}

	/**
	 * Remove a credit fragment sitting at the very end of a block element.
	 *
	 * Handles "…across several regions. Source: <a>Noah Wire Services</a></p>" and the
	 * "<br />Source: …" variant, where the credit shares an element with real prose so
	 * the whole element must not be dropped.
	 *
	 * "via" is excluded here: as an ordinary English word it is too risky at the end of
	 * a sentence, whereas a whole element reading "Via: X" is unambiguous.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	private static function strip_trailing_credit( $html ) {
		$labels = 'source|sources|credit|credits|attribution|courtesy';
		$inline = 'em|strong|small|span|i|b';

		$pattern = '~'
			// Optional separator before the fragment.
			. '(?:<br\s*/?>|\s|&nbsp;)*'
			// Optional inline wrapper opening tags.
			. '(?:<(?:' . $inline . ')\b[^>]*>\s*)*'
			// The label and its separator.
			. '(?:' . $labels . ')\s*[:\-–—]\s*'
			// The credited name, optionally hyperlinked, kept short.
			. '(?:<a\b[^>]*>)?[^<>]{1,80}(?:</a>)?'
			// Optional inline wrapper closing tags.
			. '(?:\s*</(?:' . $inline . ')>)*'
			. '\s*'
			// Must sit immediately before the end of a block.
			. '(?=</(?:p|div|li|figcaption|h[1-6])>)'
			. '~iu';

		$result = preg_replace( $pattern, '', $html );

		return null === $result ? $html : $result;
	}

	/**
	 * Drop block elements left with no content by the passes above.
	 *
	 * @param string $html Content.
	 * @return string
	 */
	private static function remove_empty_blocks( $html ) {
		$result = preg_replace(
			'~<(p|div|li|figcaption)\b[^>]*>(?:\s|<br\s*/?>|&nbsp;|&#160;)*</\1>~i',
			'',
			$html
		);

		return trim( null === $result ? $html : $result );
	}

	/**
	 * Whether a normalised string is a credit line rather than body prose.
	 *
	 * @param string $text Normalised text.
	 * @return bool
	 */
	private static function is_credit_text( $text ) {
		if ( '' === $text || mb_strlen( $text ) > self::CREDIT_MAX_LENGTH ) {
			return false;
		}

		return (bool) preg_match( self::get_credit_pattern(), $text );
	}

	/**
	 * Locate the element whose own text is exactly one of the markers.
	 *
	 * Exact matching on normalised text prevents matching an ancestor wrapper, whose
	 * textContent would contain the marker plus the rest of the article.
	 *
	 * @param \DOMDocument $dom     Parsed document.
	 * @param string[]     $markers Normalised marker labels.
	 * @return \DOMNode|null
	 */
	private static function find_marker_node( $dom, $markers ) {
		if ( empty( $markers ) ) {
			return null;
		}

		foreach ( $dom->getElementsByTagName( '*' ) as $element ) {
			$text = self::normalise( $element->textContent );

			if ( '' === $text || ! in_array( $text, $markers, true ) ) {
				continue;
			}

			/*
			 * Prefer the outermost node containing nothing but the marker, so a
			 * <p><strong>Source Reference Map</strong></p> wrapper is removed whole
			 * rather than leaving an empty paragraph behind.
			 */
			$node = $element;
			while (
				$node->parentNode
				&& XML_ELEMENT_NODE === $node->parentNode->nodeType
				&& ! in_array( strtolower( $node->parentNode->nodeName ), [ 'body', 'html' ], true )
				&& self::normalise( $node->parentNode->textContent ) === $text
			) {
				$node = $node->parentNode;
			}

			return $node;
		}

		return null;
	}

	/**
	 * String-based fallback for environments without DOM support.
	 *
	 * @param string $html          Post content.
	 * @param bool   $remove_credit Also remove credit lines.
	 * @return string
	 */
	private static function strip_with_string( $html, $remove_credit ) {
		foreach ( self::get_markers() as $marker ) {
			$words   = preg_split( '/\s+/', $marker );
			$pattern = '/' . implode( '[\s\x{00A0}]+', array_map( 'preg_quote', $words ) ) . '/iu';

			if ( ! preg_match( $pattern, $html, $m, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$start = (int) $m[0][1];

			// Walk back to the opening tag that contains the marker.
			$tag_start = strrpos( substr( $html, 0, $start ), '<' );
			if ( false !== $tag_start ) {
				$start = $tag_start;
			}

			$end = strlen( $html );
			if ( ! $remove_credit && preg_match( '/<[^>]*>\s*(source|credit|attribution)\s*[:\-]/i', $html, $sm, PREG_OFFSET_CAPTURE, $start ) ) {
				$end = (int) $sm[0][1];
			}

			$html = substr( $html, 0, $start ) . substr( $html, $end );
		}

		if ( $remove_credit ) {
			// Drop short standalone elements that are just a credit line.
			$html = preg_replace(
				'/<(p|div|h[1-6]|span|em|small|figcaption)\b[^>]*>(?:\s|<[^>]+>)*(?:source|sources|credit|credits|attribution|courtesy|via)\s*[:\-–—]\s*[^<]{1,180}<\/\1>/i',
				'',
				$html
			);
		}

		return trim( (string) $html );
	}
}
