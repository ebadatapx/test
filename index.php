<?php
xxxxxx
define('DB_NAME', 'parentscanvas_db');
define('DB_USER', 'parentscanvas_user');
define('DB_PASSWORD', '*9Z!13ub(^igGF?6');
define('DB_HOST', 'localhost');

define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = 'wp_1530c4108f_';

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', '/home/parentscanvas/logs/wp-debug.log');
define('WP_DEBUG_DISPLAY', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');

define('DISABLE_WP_CRON', true);

define('AUTH_KEY', 'A_9dmYcasjt&H,52KlBoT{5++<,hAkq_:&?{]C&I6>=:aijX<83(hnj.)q>?>yA@');
define('SECURE_AUTH_KEY', ':b8hIRi_nfbM%%^Ch[wRNO3Xu+vdosLBk}dC%xeTwUcFs7g5(elNOpOQ)bWD^eJK');
define('LOGGED_IN_KEY', 'IR#nLtPF*tJ1yFLQ:c=2Vrz2BVUY^T}NW3YE=2>*uvnsyW3?Rt}#1&P@,ibQD{,i');
define('NONCE_KEY', ',ud_5X{TEisI&BwM<#sd!p3v43TM=-:864Sxmz*-Quh8@IQ,dMq2?K+5Ns,M[,MP');
define('AUTH_SALT', 'N%U}NGIZ^2FA5_SX{bZEo=cj(qQ4B^c]m&tSNtBW,pf5&}H(>pFNX]VqHuqIH9gr');
define('SECURE_AUTH_SALT', ')2,piB6u(Nk{QkO<0E&x{_9AqilRVW2pfAe,pOcC?wo)K(;8hsQD5:{5%:%NL;IG');
define('LOGGED_IN_SALT', '0ka!Rt6B=JzNV%pL[Tw,OfE}2Sk8{E&jyh{xq,50nf;W)45},N4[]J_aRJGD]>.D');
define('NONCE_SALT', '@v0vkx^Gsm0C)nC;s=&Uynu^YJLW^t6x%ZQ08O7=1g,1sJtVOIVy_p>awe0*(Ac@');

require_once ABSPATH . 'wp-settings.php';