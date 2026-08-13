<?php

namespace HScript\Template;

use HScript\Application;
use HScript\Mail\Mailer;
use HScript\Util\StringHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Configures Twig and exposes compatibility helpers used by templates.
 *
 * Theme-specific overrides are loaded before the default template directory.
 */
final class View
{
private static ?Environment $environment = null;
private static ?FilesystemLoader $loader = null;
private static array $context = [];
private static array $errors = [];
private static array $translationCache = [];
private static array $dateFormats = [
    ["% H:i", "* j, Y", "MDYHI", "m/d/y h:m", "m/d/y", "m" => ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"], "f" => ["yesterday", "today", "tomorrow"]]
];

public static function initialize(): void
{
    if (self::$environment)
        return;

    self::$loader = new FilesystemLoader(self::tplTemplatePaths());
    self::$environment = new Environment(self::$loader, [
        'cache' => is_dir('tpl_c') ? 'tpl_c' : false,
        'auto_reload' => true,
        'autoescape' => 'html',
    ]);
    self::tplRegisterLegacyPlugins();
}

public static function tplTemplatePaths($templ = '')
{
    global $_GS;
    $paths = [];
    $lang = isset($_GS['lang']) ? (string)$_GS['lang'] : '';
    $lang_dir = isset($_GS['lang_dir']) ? (string)$_GS['lang_dir'] : '';
    $mode = self::normalizeTemplateMode($_GS['mode'] ?? '');
    if ($mode !== '' && self::templateModeExists($mode)) {
        $mode_dir = 'tpl/themes/' . $mode;
        $paths[] = $mode_dir;
    }
    if ($templ !== '' && $lang !== '') {
        $local_lang_dir = "tpl.local/" . $lang . "/";
        if (is_file($local_lang_dir . $templ . ".twig")) {
            $paths[] = $local_lang_dir;
        }
    }
    if (is_dir('tpl')) {
        $paths[] = 'tpl';
    }
    if ($lang_dir !== '' && is_dir($lang_dir)) {
        $paths[] = $lang_dir;
    }
    return array_values(array_unique($paths ?: ['tpl']));
}

public static function tplSetTemplateDir($dir)
{
    self::initialize();
    $paths = [];
    foreach ((array)$dir as $path) {
        if (is_dir($path)) {
            $paths[] = $path;
        }
    }
    if ($paths) {
        self::$loader->setPaths(array_values(array_unique($paths)));
    }
}

public static function tplModuleToLink($module = '', $chpu = false, $https = 0)
{
    if (!function_exists('moduleToLink')) {
        return '';
    }
    return moduleToLink($module, $chpu, $https);
}

public static function tplIsset(...$values)
{
    foreach ($values as $value) {
        if ($value === null) {
            return false;
        }
    }
    return true;
}

public static function tplEmpty($value = null)
{
    return empty($value);
}

public static function tplCount($value = null)
{
    return is_countable($value) ? count($value) : 0;
}

public static function tplCat($value = '', ...$parts)
{
    return (string)$value . implode('', array_map('strval', $parts));
}

public static function tplDateFormat($value = null, $format = '%Y')
{
    $timestamp = $value ?: time();
    if (!is_numeric($timestamp)) {
        $timestamp = strtotime((string)$timestamp);
    }
    if (!$timestamp) {
        return '';
    }
    $map = [
        '%Y' => 'Y',
        '%y' => 'y',
        '%m' => 'm',
        '%d' => 'd',
        '%H' => 'H',
        '%M' => 'i',
        '%S' => 's',
    ];
    return date(strtr((string)$format, $map), (int)$timestamp);
}

public static function tplTruncate($value = '', $length = 80, $suffix = '...')
{
    $value = (string)$value;
    $length = max(0, (int)$length);
    if ($length <= 0 || mb_strlen($value, 'UTF-8') <= $length) {
        return $value;
    }
    $suffix = (string)$suffix;
    $cut = max(0, $length - mb_strlen($suffix, 'UTF-8'));
    return mb_substr($value, 0, $cut, 'UTF-8') . $suffix;
}

public static function tplStringFormat($value = '', $format = '%s')
{
    return sprintf((string)$format, $value);
}

public static function tplImplode($glue = '', $pieces = [])
{
    if (!is_array($pieces)) {
        return (string)$pieces;
    }
    return implode((string)$glue, $pieces);
}

public static function tplExplode($delimiter = '', $value = '')
{
    return explode((string)$delimiter, (string)$value);
}

public static function tplTemplateName($name = '')
{
    return preg_replace('/\.tpl$/', '.twig', (string)$name);
}

public static function tplMergeArrays($left = [], $right = [])
{
    return (array)$left + (array)$right;
}

public static function tplBitwiseAnd($left = 0, $right = 0)
{
    return ((int)$left) & ((int)$right);
}

public static function tplArrayAppend($array = [], $value = null)
{
    $array = (array)$array;
    $array[] = $value;
    return $array;
}

public static function tplArraySetPath($array = [], $path = [], $value = null)
{
    $array = (array)$array;
    $cursor =& $array;
    foreach ((array)$path as $key) {
        if (!is_array($cursor)) {
            $cursor = [];
        }
        if (!array_key_exists($key, $cursor) || !is_array($cursor[$key])) {
            $cursor[$key] = [];
        }
        $cursor =& $cursor[$key];
    }
    $cursor = $value;
    unset($cursor);
    return $array;
}

public static function tplArrayValue($array = [], $path = '')
{
    $source = is_array($array) ? $array : [];
    return _arr_val($source, (string)$path);
}

public static function tplFirstValue($value = null)
{
    if (!is_array($value) || !$value) {
        return false;
    }
    return reset($value);
}

public static function tplLegacyDictionaries()
{
    $translate = static function (string $key, string $default): string {
        return (string)self::tplTranslate([
            'key' => $key,
            'default' => $default,
        ]);
    };

    return [
        'op_names' => [
            'BONUS' => $translate('operation.name.bonus', 'Бонус'),
            'PENALTY' => $translate('operation.name.penalty', 'Штраф'),
            'CASHIN' => $translate('operation.name.cashin', 'Пополнение'),
            'CASHOUT' => $translate('operation.name.cashout', 'Вывод'),
            'EX' => $translate('operation.name.exchange_out', 'Исх. обмен'),
            'EXIN' => $translate('operation.name.exchange_in', 'Вх. обмен'),
            'TR' => $translate('operation.name.transfer_out', 'Перевод'),
            'TRIN' => $translate('operation.name.transfer_in', 'Приход'),
            'BUY' => $translate('operation.name.buy', 'Покупка'),
            'SELL' => $translate('operation.name.sell', 'Продажа'),
            'BUY2' => $translate('operation.name.service_buy', 'Услуга'),
            'SELL2' => $translate('operation.name.service_sell', 'Оказание услуги'),
            'REF' => $translate('operation.name.referral', 'Реферальное начисление'),
            'GIVE' => $translate('operation.name.deposit_open', 'Вклад'),
            'TAKE' => $translate('operation.name.deposit_close', 'Снятие'),
            'CALCIN' => $translate('operation.name.accrual', 'Начисление'),
            'CALCOUT' => $translate('operation.name.deduction', 'Отчисление')
        ],
        'op_statuses' => [
            0 => $translate('operation.status.confirmation', 'Ожидает подтверждения'),
            1 => $translate('operation.status.payment', 'Ожидает пополнения'),
            2 => $translate('operation.status.processing', 'Ожидает выполнения'),
            3 => $translate('operation.status.completed', 'Выполнена'),
            4 => $translate('operation.status.rejected', 'Отклонена'),
            5 => $translate('operation.status.cancelled', 'Отменена')
        ],
        'op_sums' => [
            'BONUS' => $translate('operation.amount.default', 'Сумма'),
            'PENALTY' => $translate('operation.amount.default', 'Сумма'),
            'CASHIN' => $translate('operation.amount.cashin', 'Сумма к пополнению'),
            'CASHOUT' => $translate('operation.amount.cashout', 'Сумма к выводу'),
            'EX' => $translate('operation.amount.exchange', 'Сумма к обмену'),
            'EXIN' => $translate('operation.amount.received', 'Полученная сумма'),
            'TR' => $translate('operation.amount.to_receive', 'Сумма к получению'),
            'TRIN' => $translate('operation.amount.received', 'Полученная сумма'),
            'BUY' => $translate('operation.amount.default', 'Сумма'),
            'SELL' => $translate('operation.amount.default', 'Сумма'),
            'BUY2' => $translate('operation.amount.default', 'Сумма'),
            'SELL2' => $translate('operation.amount.default', 'Сумма'),
            'REF' => $translate('operation.amount.default', 'Сумма'),
            'GIVE' => $translate('operation.amount.deposit', 'Сумма вклада'),
            'TAKE' => $translate('operation.amount.to_receive', 'Сумма к получению'),
            'CALCIN' => $translate('operation.amount.default', 'Сумма'),
            'CALCOUT' => $translate('operation.amount.default', 'Сумма')
        ],
        'usr_statuses' => [
            0 => $translate('user.status.inactive', 'не активен'),
            1 => $translate('user.status.active', 'активен'),
            2 => $translate('user.status.penalized', 'наказан'),
            3 => $translate('user.status.blocked', 'заблокирован'),
            4 => $translate('user.status.reserve', 'резерв')
        ],
        'ststrs' => [
            0 => $translate('deposit.status.cancelled', 'Отменен'),
            1 => $translate('deposit.status.active', 'Активен'),
            2 => $translate('deposit.status.finished', 'Окончен'),
            3 => $translate('deposit.status.closed', 'Закрыт')
        ],
        '_tstates' => [
            1 => $translate('ticket.status.new', 'Новый'),
            2 => $translate('ticket.status.waiting', 'Ожидает ответа'),
            3 => $translate('ticket.status.processing', 'В процессе'),
            4 => $translate('ticket.status.processed', 'Обработан'),
            5 => $translate('ticket.status.answered', 'Ответ отправлен'),
            8 => $translate('ticket.status.delayed', 'Задержан'),
            9 => $translate('ticket.status.closed', 'Закрыт')
        ],
        'sms_statuses' => [
            0 => $translate('sms.status.waiting', 'ожидает'),
            1 => $translate('sms.status.processing', 'обрабатывается'),
            2 => $translate('sms.status.sent', 'отправлено'),
            3 => $translate('sms.status.delivered', 'доставлено'),
            4 => $translate('sms.status.error', 'ошибка'),
            9 => $translate('sms.status.delayed', 'отложено')
        ]
    ];
}

public static function tplRegisterLegacyPlugins()
{
    self::initialize();
    static $registered = [];
    $functions = [
        '_t' => [[self::class, 'tplTranslate'], ['is_variadic' => true]],
        '_getFormSecurity' => [[self::class, 'tplFormSecurity'], ['is_safe' => ['html']]],
        '_getFormCaptcha' => [[self::class, 'tplFormCaptcha'], ['is_safe' => ['html']]],
        '_link' => [[self::class, 'tplModuleToLink'], []],
        'z' => [[self::class, 'tplFormatAmount'], ['is_safe' => ['html']]],
        'formErrorText' => [[self::class, 'tplFormErrorText'], []],
        'isset' => [[self::class, 'tplIsset'], []],
        'empty' => [[self::class, 'tplEmpty'], []],
        'count' => [[self::class, 'tplCount'], []],
        'tpl_name' => [[self::class, 'tplTemplateName'], []],
        'merge_arrays' => [[self::class, 'tplMergeArrays'], []],
        'bitwise_and' => [[self::class, 'tplBitwiseAnd'], []],
        'array_append' => [[self::class, 'tplArrayAppend'], []],
        'array_set_path' => [[self::class, 'tplArraySetPath'], []],
        '_arr_val' => [[self::class, 'tplArrayValue'], []],
    ];
    foreach ($functions as $name => $config) {
        $callback = $config[0];
        if (empty($registered['function:' . $name])) {
            self::$environment->addFunction(new TwigFunction($name, $callback, $config[1]));
            $registered['function:' . $name] = true;
        }
    }
    $filters = [
        '_z',
        '_t',
        '_uid',
        'moduleToLink',
        'fullURL',
        'valueIf',
        'exValue',
        'firstNotEmpty',
        'getDomain',
        'getInfoData',
        'getFormName',
        '_arr_val',
        'isset_IN',
        '_IN',
        'textLangFilter',
        'textLeft',
        'textRight',
        'timeToStr',
        'stampToTime',
        'textToTime',
        'giGetCountry',
        'FullURL',
        'reset',
        'count',
        'substr',
        'strpos',
        'strlen',
        'trim',
        'str_replace',
        'md5',
        'base64_encode',
        'array_search',
        'is_int',
        'is_array',
        'in_array',
        'abs'
    ];
    $classCallbacks = [
        '_t' => [self::class, '_t'],
        'getInfoData' => [self::class, 'getInfoData'],
        'getFormName' => [self::class, 'getFormName'],
        '_arr_val' => [self::class, 'tplArrayValue'],
        'timeToStr' => [self::class, 'timeToStr'],
        'textToTime' => [self::class, 'textToTime'],
        'valueIf' => [StringHelper::class, 'valueIf'],
        'exValue' => [StringHelper::class, 'exValue'],
        'firstNotEmpty' => [StringHelper::class, 'firstNotEmpty'],
        'textLangFilter' => [StringHelper::class, 'textLangFilter'],
        'textLeft' => [StringHelper::class, 'textLeft'],
        'textRight' => [StringHelper::class, 'textRight'],
        'textPos' => [StringHelper::class, 'textPos'],
        'TextPos' => [StringHelper::class, 'textPos'],
        'reset' => [self::class, 'tplFirstValue'],
    ];
    $resolveCallback = static function (string $name) use ($classCallbacks) {
        if (isset($classCallbacks[$name]))
            return $classCallbacks[$name];
        return function_exists($name) ? $name : null;
    };
    $legacyFunctions = array_merge($filters, [
        '_z',
        '_uid',
        'getFormName',
        'getInfoData',
        'firstNotEmpty',
        'textLeft',
        'textRight',
        'textPos',
        'TextPos',
    ]);
    foreach (array_unique($legacyFunctions) as $fn) {
        $callback = $resolveCallback($fn);
        if ($callback && empty($registered['function:' . $fn])) {
            $options = ($fn === '_z') ? ['is_safe' => ['html']] : [];
            self::$environment->addFunction(new TwigFunction($fn, $callback, $options));
            $registered['function:' . $fn] = true;
        }
    }
    $customFilters = [
        'cat' => [self::class, 'tplCat'],
        'date_format' => [self::class, 'tplDateFormat'],
        'truncate' => [self::class, 'tplTruncate'],
        'string_format' => [self::class, 'tplStringFormat'],
        'implode' => [self::class, 'tplImplode'],
        'explode' => [self::class, 'tplExplode'],
        'tpl_name' => [self::class, 'tplTemplateName'],
        '_arr_val' => [self::class, 'tplArrayValue'],
    ];
    foreach ($customFilters as $name => $callback) {
        if (empty($registered['filter:' . $name])) {
            self::$environment->addFilter(new TwigFilter($name, $callback));
            $registered['filter:' . $name] = true;
        }
    }
    foreach ($filters as $fn) {
        $callback = $resolveCallback($fn);
        if ($callback && empty($registered['filter:' . $fn])) {
            $options = ($fn === '_z') ? ['is_safe' => ['html']] : [];
            self::$environment->addFilter(new TwigFilter($fn, $callback, $options));
            $registered['filter:' . $fn] = true;
        }
    }
}

public static function translationPath($lang)
{
    $lang = preg_replace('/[^a-z0-9_-]/i', '', (string)$lang);
    return 'lang/' . $lang . '.json';
}

public static function translationReadFile($lang)
{
	$base = self::translationReadBundledFile($lang);
	$overrides = self::translationReadOverrides($lang);
	return array_replace($base, $overrides);
}

public static function translationReadBundledFile($lang)
{
    $file = self::translationPath($lang);
    if (!is_file($file)) {
        return array();
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : array();
}

public static function translationReadOverrides($lang)
{
	global $_cfg;

	$lang = preg_replace('/[^a-z0-9_-]/i', '', (string)$lang);
	$key = 'Translations_' . $lang;
	if ($lang === '' || empty($_cfg[$key]) || !is_string($_cfg[$key])) {
		return array();
	}
	$data = json_decode($_cfg[$key], true);
	return is_array($data) ? $data : array();
}

public static function translationLanguages()
{
	global $_cfg;

	$langs = array();
	$append = static function ($lang) use (&$langs): void
	{
		$lang = strtolower(trim((string)$lang));
		if (preg_match('/^[a-z]{2,3}(?:-[a-z]{2})?$/', $lang) && !in_array($lang, $langs, true)) {
			$langs[] = $lang;
		}
	};
	$configured = $_cfg['UI__Langs'] ?? $_cfg['_Langs'] ?? array();
	if (!is_array($configured)) {
		$configured = explode("\n", str_replace("\r", '', (string)$configured));
	}
	foreach ($configured as $lang) {
		$append($lang);
	}
	foreach (glob('lang/*.json') ?: array() as $file) {
		$append(basename($file, '.json'));
	}
	return $langs ?: array('en', 'ru');
}

public static function translationLoad($lang = '')
{
    global $_GS;
    if ($lang === '') {
        $lang = isset($_GS['lang']) ? $_GS['lang'] : '';
    }
    $lang = self::getLang($lang);
    if (!isset(self::$translationCache[$lang])) {
        self::$translationCache[$lang] = self::translationReadFile($lang);
    }
    return self::$translationCache[$lang];
}

public static function translationClearCache()
{
    self::$translationCache = [];
}

public static function translationReplaceParams($text, $params = array())
{
    if (!$params || !is_array($params)) {
        return $text;
    }
    foreach ($params as $key => $value) {
        if (is_scalar($value)) {
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
    }
    return $text;
}

public static function _t($key, $params = array(), $lang = '')
{
    global $_GS;
    $key = (string)$key;
    $data = self::translationLoad($lang);
    if (array_key_exists($key, $data)) {
        return self::translationReplaceParams($data[$key], $params);
    }
    $defaultLang = isset($_GS['default_lang']) ? $_GS['default_lang'] : 'en';
    if ($lang !== $defaultLang) {
        $fallback = self::translationLoad($defaultLang);
        if (array_key_exists($key, $fallback)) {
            return self::translationReplaceParams($fallback[$key], $params);
        }
    }
    return $key;
}

public static function tplTranslate($key = '', $params = [], $lang = '', $default = '', $assign = '', array $extra = [])
{
    if (is_array($key)) {
        $params = $key;
        $key = isset($params['key']) ? $params['key'] : (isset($params[0]) ? $params[0] : '');
        $lang = isset($params['lang']) ? $params['lang'] : '';
        $default = isset($params['default']) ? $params['default'] : '';
        $assign = isset($params['assign']) ? $params['assign'] : '';
        unset($params['key'], $params['lang'], $params['default'], $params['assign']);
    } elseif (!is_array($params)) {
        $params = [];
    }
    if (is_array($extra)) {
        $params += $extra;
    }
    $value = self::_t($key, $params, $lang);
    if ($default !== '' && $value === (string)$key) {
        $value = self::translationReplaceParams($default, $params);
    }
    if ($assign !== '') {
        self::setPage($assign, $value, 0);
        return '';
    }
    return $value;
}

public static function tplFormatAmount($value = 0, $cid = 0, $dec = 0)
{
    if (is_array($value)) {
        $params = $value;
        $value = isset($params['value']) ? $params['value'] : 0;
        $cid = isset($params['cid']) ? $params['cid'] : 0;
        $dec = isset($params['dec']) ? $params['dec'] : 0;
    }
    return function_exists('_z') ? _z($value, $cid, $dec) : $value;
}

public static function tplNormalizeFormSecurityParams($form = "", $captcha = 0, $fallbackCaptcha = 0)
{
    if (is_array($captcha) && (array_key_exists("form", $captcha) || array_key_exists("captcha", $captcha))) {
        $params = $captcha;
        $form = isset($params["form"]) ? $params["form"] : "";
        $captcha = isset($params["captcha"]) ? $params["captcha"] : 0;
    } elseif (is_array($form) && (array_key_exists("form", $form) || array_key_exists("captcha", $form))) {
        $params = $form;
        $form = isset($params["form"]) ? $params["form"] : "";
        $captcha = isset($params["captcha"]) ? $params["captcha"] : 0;
    } elseif (is_array($form)) {
        $form = is_scalar($captcha) ? $captcha : "";
        $captcha = $fallbackCaptcha;
    }

    return [self::getFormName($form), (int)$captcha];
}

public static function tplFormErrorText($form = "", $errors = [], $consume = false)
{
    $form = self::getFormName($form);
    if (empty(self::$errors[$form]) || !is_array(self::$errors[$form])) {
        return "";
    }

    $messages = [];
    if (is_array($errors) && $errors) {
        foreach ($errors as $code => $message) {
            $index = array_search($code, self::$errors[$form]);
            if ($index !== false) {
                $messages[] = (string)$message;
                if ($consume) {
                    self::$errors[$form][$index] = null;
                }
            }
        }
    } else {
        foreach (self::$errors[$form] as $message) {
            if ($message) {
                $messages[] = (string)$message;
            }
        }
    }

    return $messages ? implode("; ", $messages) : "";
}

public static function translationLoadAll($langs = array())
{
	if (!is_array($langs) || !$langs) {
		$langs = self::translationLanguages();
	}
    $all = array();
    foreach ($langs as $lang) {
        $all[$lang] = self::translationReadFile($lang);
    }
    return $all;
}

public static function translationSaveAll($translations)
{
	global $db, $_cfg;

	if (!is_object($db) || !is_array($translations)) {
		return false;
	}
    foreach ($translations as $lang => $data) {
		$lang = preg_replace('/[^a-z0-9_-]/i', '', (string)$lang);
		if ($lang === '') {
			continue;
		}
        if (!is_array($data)) {
            $data = array();
        }
		$base = self::translationReadBundledFile($lang);
		$overrides = array();
		foreach ($data as $key => $value) {
			$value = (string)$value;
			if (!array_key_exists($key, $base) || (string)$base[$key] !== $value) {
				$overrides[$key] = $value;
			}
		}
		ksort($overrides, SORT_NATURAL | SORT_FLAG_CASE);
		$json = json_encode($overrides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }
		if (!$db->replace('Cfg', array(
			'Module' => 'Translations',
			'Prop' => $lang,
			'Val' => $json,
		))) {
            return false;
        }
		$_cfg['Translations_' . $lang] = $json;
    }
	self::translationClearCache();
    return true;
}

public static function existLang($lang)
{
    if (StringHelper::sEmpty($lang) || $lang == "." || $lang == "..") {
        return false;
    }
    return is_dir("tpl/" . $lang) || is_file("lang/" . $lang . ".json") || in_array($lang, array('ru', 'en'));
}
public static function getLang($lang = "")
{
    global $_GS;
    if ($lang == "") {
        $lang = $_GS["lang"];
    } else {
        if ($lang == "*") {
            $lang = $_GS["default_lang"];
        }
    }
    if (self::existLang($lang)) {
        return $lang;
    }
    return $_GS["default_lang"];
}
public static function getLangDir($lang = "")
{
    global $_GS;
    $dir = "tpl/";
    foreach ([
        self::getLang($lang),
        self::normalizeTemplateMode($_GS["mode"] ?? ''),
        self::normalizeTemplateMode($_GS["theme"] ?? ''),
    ] as $d) {
        if (!StringHelper::sEmpty($d)) {
            if (is_dir($dir . $d)) {
                $dir .= $d . "/";
            }
        }
    }
    return $dir;
}
public static function normalizeTemplateMode($mode): string
{
    $mode = trim((string)$mode);
    if ($mode === '') {
        return '';
    }
    return preg_match('/^[a-z0-9][a-z0-9_-]{0,9}$/i', $mode) ? $mode : '';
}
public static function templateModeExists($mode): bool
{
    $mode = self::normalizeTemplateMode($mode);
    return $mode !== ''
        && !is_dir('tpl/' . $mode)
        && is_dir('tpl/themes/' . $mode);
}
public static function prepVal(&$vl, $conv)
{
    global $_GS;
    if (!is_array($vl)) {
        if ($conv & 1) {
            $vl = StringHelper::textLangFilter($vl, $_GS["lang"]);
        }
        if ($conv & 2) {
            $vl = htmlspecialchars((string)$vl, ENT_QUOTES);
        } else {
            if ($conv & 4) {
                $vl = strip_tags((string)$vl);
            }
        }
    } else {
        foreach ($vl as $f => $v) {
            self::prepVal($vl[$f], $conv);
        }
    }
}
public static function setPage($par, $val, $conv = 3)
{
    if (0 < $conv) {
        self::prepVal($val, $conv);
    }
    self::$context[$par] = $val;
}
public static function showPage($templ = "", $module = false, $exit_after = true)
{
    global $_IN;
    global $_GS;
    global $_cfg;
    self::initialize();
    // Some legacy helpers (_z, _uid and module callbacks) are loaded only
    // after Twig is initialized. Refresh the registry immediately before
    // compiling a template so these runtime functions are always available.
    self::tplRegisterLegacyPlugins();
    if ($module === false) {
        $module = $_GS["module"];
    }
    $tpl_module = $module;
    if (file_exists($_GS["module_dir"] . $module . ".php")) {
        $t = StringHelper::cutElemR($module, "/");
        if (!$templ) {
            $templ = $t;
        }
    } else {
        if (!$templ) {
            $templ = "index";
        }
    }
    $template = $module . "/" . $templ;
    $lang = $_GS["lang"];
    self::loadDateFormat($lang);
    self::$context = array_replace(self::tplLegacyDictionaries(), self::$context, [
        'tpl_module' => $tpl_module,
        'tpl_vmodule' => $_GS["vmodule"],
        'tpl_name' => $templ,
        'tpl_filename' => $template,
        'InputDateFormatLong' => trim(self::$dateFormats[$lang][3]),
        'InputDateFormat' => trim(self::$dateFormats[$lang][4]),
        'tpl_time' => time() + $_GS["TZ"],
		'app_name' => Application::NAME,
		'app_version' => Application::VERSION,
		'app_license' => Application::LICENSE,
        '_IN' => $_IN,
        '_GET' => $_GET,
        '_POST' => $_POST,
        '_SESSION' => isset($_SESSION) ? $_SESSION : [],
        '_COOKIE' => $_COOKIE,
        'tpl_info' => self::getInfoData("*"),
        'tpl_errors' => self::$errors,
    ]);
    self::tplSetTemplateDir(self::tplTemplatePaths($template));
    if (!empty($_cfg["Sys_ForceCharset"]) && !headers_sent()) {
        header("Content-Type: text/html; charset=utf-8");
    }
    echo self::$environment->render($template . ".twig", self::$context);
    if ($exit_after) {
        exit;
    }
}
public static function showInfo($code = "Completed", $url = "*", $data = [])
{
    $url = fullURL($url);
    $_SESSION["_show_info"][$url] = [$code, $data];
    goToURL($url);
}
public static function showSplash($code = "Completed", $url = "*", $data = [], $templ = "splash", $tm = 0)
{
    $url = fullURL($url);
    $_SESSION["_show_info"][$url] = [$code, $data];
    if ($tm < 1) {
        $tm = substr($code, 0, 1) == "*" ? 3 : 1;
    }
    refreshToURL($tm, $url);
    self::setPage("url", $url);
    self::showPage($templ);
}
public static function showFormInfo($code = "Completed", $form = "", $data = [])
{
    $_SESSION["_show_info"][self::getFormName($form)] = [$code, $data];
    goToURL(fullURL());
}
public static function getInfoData($id = "", $and_unset = true)
{
    $id = $id == "*" ? fullURL() : self::getFormName($id);
    $info = isset($_SESSION["_show_info"][$id]) ? $_SESSION["_show_info"][$id] : null;
    if ($and_unset) {
        unset($_SESSION["_show_info"][$id]);
    }
    return $info;
}
public static function getFormName($form = "")
{
    global $_GS;
    if (!$form || is_int($form)) {
        return $_GS["module"] . "_frm" . $form;
    }
    return $form;
}
public static function sendedForm($btn = "", $form = "")
{
    global $_IN;
    $form = self::getFormName($form) . "_btn" . $btn;
    $res = isset_IN($form);
    unset($_IN[$form]);
    return $res;
}
public static function setError($e, $form = "", $and_break = true)
{
    if (!is_string($e)) {
        return NULL;
    }
    self::$errors[self::getFormName($form)][] = $e;
    if ($and_break) {
        xAbort($e);
    }
}
public static function breakIfError($form = "", $e = "Error")
{
    if (0 < count(self::$errors[self::getFormName($form)] ?? [])) {
        xAbort($e);
    }
}
public static function loadText($section, $file = "texts", $lang = "")
{
    $file = self::getLangDir($lang) . (string) $file . ".lng";
    if (!file_exists($file)) {
        return false;
    }
    $res = [];
    $celem = "";
    $is = false;
    $h = fopen($file, "r");
    while (!feof($h)) {
        $s = trim(fgets($h, 4096));
        if (substr($s, 0, 2) == "//") {
            continue;
        }
        if (substr($s, 0, 1) == "[" && substr($s, -1) == "]") {
            if ($is && StringHelper::textPos(".", $celem) < 0) {
                break;
            }
            $celem = trim(substr($s, 1, -1));
            $is = StringHelper::get1ElemL($celem, ".") == $section;
        } else {
            if ($is) {
                if (!isset($res[$celem])) {
                    $res[$celem] = '';
                }
                $res[$celem] .= $s . HS2_NL;
            }
        }
    }
    fclose($h);
    return $res;
}
public static function sendMailToUser($mail, $section, $consts = [], $lang = "", $scope = "user")
{
    global $_cfg;
    if (!validMail($mail) || !$section) {
        return false;
    }
	$scope = $scope === 'admin' ? 'admin' : 'user';
	$content = self::emailContent($section, $consts, $lang, $scope);
	if (!$content) {
        return false;
    }
	return Mailer::send(
		$mail,
		$content['subject'],
		$content['message'],
		$_cfg["Sys_NotifyMail"]
	);
}
public static function sendMailToAdmin($section, $consts = [])
{
    global $_cfg;
    $lang = !empty($_cfg["Sys_AdminLang"]) ? $_cfg["Sys_AdminLang"] : 'en';
    return self::sendMailToUser($_cfg["Sys_AdminMail"], $section, $consts, $lang, "admin");
}

public static function emailContent($section, $consts = array(), $lang = '', $scope = 'user')
{
	global $_GS, $_cfg;

	$scope = $scope === 'admin' ? 'admin' : 'user';
	$section = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$section));
	if ($section === '') {
		return array();
	}
	$lang = self::getLang($lang);
	$consts = is_array($consts) ? $consts : array();
	$consts['date'] = self::timeToStr(time(), 0, $lang);
	$consts['ip'] = (string)($_GS['client_ip'] ?? '');
	$consts['rooturl'] = (string)($_GS['root_url'] ?? '');
	$consts['sitename'] = (string)($_cfg['Sys_SiteName'] ?? $_GS['site_name'] ?? 'H-Script');
	$oper = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)($consts['oper'] ?? '')));
	if ($oper !== '') {
		$operName = self::emailTranslationValue('mail.operation_name.' . $oper, array(), $lang, true);
		$consts['oper_name'] = $operName !== '' ? $operName : strtoupper($oper);
	}
	self::prepVal($consts, 2);

	$prefix = 'mail.' . $scope . '.';
	$subject = self::emailTranslationValue($prefix . $section . '.subject', $consts, $lang);
	$body = self::emailTranslationValue($prefix . $section . '.body', $consts, $lang);
	if (($subject === '' || $body === '') && str_starts_with($section, 'oper')) {
		$subject = self::emailTranslationValue($prefix . 'operation.subject', $consts, $lang);
		$body = self::emailTranslationValue($prefix . 'operation.body', $consts, $lang);
	}
	if ($subject === '' || $body === '') {
		return array();
	}
	$header = self::emailTranslationValue($prefix . 'header', $consts, $lang, true);
	$footer = self::emailTranslationValue($prefix . 'footer', $consts, $lang, true);
	$parts = array_values(array_filter(
		array($header, $body, $footer),
		static fn($value): bool => $value !== ''
	));
	return array(
		'subject' => $subject,
		'message' => implode(HS2_NL . HS2_NL, $parts),
		'language' => $lang,
	);
}

private static function emailTranslationValue($key, $params, $lang, $allowEmpty = false)
{
	global $_GS;

	$candidates = array($lang, $_GS['default_lang'] ?? 'en', 'en', 'ru');
	foreach (array_values(array_unique($candidates)) as $candidate) {
		$data = self::translationLoad($candidate);
		if (!array_key_exists($key, $data)) {
			continue;
		}
		$value = (string)$data[$key];
		if ($value !== '' || $allowEmpty) {
			return self::translationReplaceParams($value, $params);
		}
	}
	return '';
}
public static function loadDateFormat(&$lang)
{
    $lang = self::getLang($lang);
    if (isset(self::$dateFormats[$lang])) {
        return NULL;
    }
    $df = self::getLangDir($lang) . "date.lng";
    if (is_readable($df)) {
        $a = file($df, FILE_IGNORE_NEW_LINES);
        if (is_array($a) && count($a) >= 3) {
            $l1 = explode("|", $a[0], 5);
            $l2 = explode("|", $a[1], 12);
            $l3 = explode("|", $a[2], 6);
            if (3 <= count($l1) && count($l2) == 12) {
                self::$dateFormats[$lang] = $l1;
                self::$dateFormats[$lang]["m"] = $l2;
                self::$dateFormats[$lang]["f"] = $l3;
            }
        }
    }
    if (!isset(self::$dateFormats[$lang])) {
        $lang = 0;
    }
}
public static function timeToStr($t, $format = 0, $lang = "", $tz = "")
{
    if (!$t) {
        return "";
    }
    global $_GS;
    self::loadDateFormat($lang);
    $s = "";
    if ($tz === "") {
        $tz = $_GS["TZ"] ?? 0;
    }
    $t += $tz;
    $t0 = time() + $tz;
    if ($format == 2) {
        $n = floor((gmmktime(0, 0, 0, gmdate("n", $t), gmdate("j", $t), gmdate("Y", $t)) - gmmktime(0, 0, 0, gmdate("n", $t0), gmdate("j", $t0), gmdate("Y", $t0))) / HS2_UNIX_DAY);
        $fc = floor(count(self::$dateFormats[$lang]["f"]) / 2);
        if (-1 * $fc <= $n && $n <= $fc) {
            $s = self::$dateFormats[$lang]["f"][$n + $fc];
        }
    }
    if (!$s) {
        $s = gmdate(self::$dateFormats[$lang][1], $t);
        $m = self::$dateFormats[$lang]["m"][-1 + gmdate("m", $t)];
        $s = StringHelper::textReplace($s, "*", $m);
    }
    if ($format != 1) {
        $s = StringHelper::textReplace(gmdate(self::$dateFormats[$lang][0], $t), "%", $s);
    }
    return $s;
}
public static function textToTime($sd, $format = 0, $lang = "", $tz = "")
{
    global $_GS;
    if (!$sd) {
        return "";
    }
    foreach (["/", "-", ":", " ", ",", ";"] as $d) {
        $sd = StringHelper::textReplace($sd, $d, ".");
    }
    $sd = StringHelper::textReplace($sd, "..", ".");
    $d = explode(".", $sd, 5);
    self::loadDateFormat($lang);
    $sd = StringHelper::textUp(self::$dateFormats[$lang][2]);
    $a = [0, 0, 0, 0, 0];
    foreach (["Y", "M", "D", "H", "I"] as $i => $c) {
        $pos = StringHelper::textPos($c, $sd);
        $a[$i] = ($pos >= 0 && isset($d[$pos])) ? $d[$pos] : 0;
    }
    if ($tz === "") {
        $tz = $_GS["TZ"];
    }
    $t0 = time() + $tz;
    if (3 <= StringHelper::textLen($d[0])) {
        foreach (self::$dateFormats[$lang]["f"] as $n => $m) {
            if (StringHelper::textPos(StringHelper::textUp($d[0]), StringHelper::textUp($m)) == 0) {
                $t = $t0 + HS2_UNIX_DAY * ($n - floor(count(self::$dateFormats[$lang]["f"]) / 2));
                $a = [gmdate("Y", $t), gmdate("n", $t), gmdate("j", $t), $d[1], $d[2]];
            }
        }
    }
    if (!intval($a[2])) {
        return "";
    }
    if (3 <= StringHelper::textLen($a[1])) {
        foreach (self::$dateFormats[$lang]["m"] as $n => $m) {
            if (StringHelper::textPos(StringHelper::textUp($a[1]), StringHelper::textUp($m)) == 0) {
                $a[1] = $n + 1;
            }
        }
    }
    if (0 < $format) {
        $a[3] = 0;
        $a[4] = 0;
    }
    if ($a[2] && !$a[0]) {
        $a[0] = gmdate("Y", $t0);
        if (!intval($a[1])) {
            $a[1] = gmdate("n", $t0);
        }
    }
    if ($t = gmmktime(intval($a[3]), intval($a[4]), 0, intval($a[1]), intval($a[2]), intval($a[0]))) {
        if ($format == 2 && 0 < $t) {
            $t += HS2_UNIX_DAY - 1;
        }
        $t -= $tz;
    }
    return $t;
}
public static function stampArrayToStr(&$a, $keys, $format = 2, $lang = "")
{
    if (is_array($a) && $a) {
        foreach (asArray($keys) as $k) {
            if (isset($a[$k])) {
                $a[$k] = self::timeToStr(stampToTime($a[$k]), $format, $lang);
            }
        }
    }
}
public static function stampTableToStr(&$a, $keys, $format = 2, $lang = "")
{
    if (is_array($a) && $a && ($keys = asArray($keys))) {
        foreach ($a as $i => $r) {
            self::stampArrayToStr($a[$i], $keys, $format, $lang);
        }
    }
}
public static function strArrayToStamp(&$a, $keys, $format = 0, $lang = "")
{
    if (is_array($a) && $a) {
        foreach (asArray($keys) as $k) {
            if (isset($a[$k])) {
                $a[$k] = timeToStamp(self::textToTime($a[$k], $format, $lang));
            }
        }
    }
}
public static function getFormCert($form = "")
{
    if (!isset($_SESSION)) {
        return false;
    }
    $form = self::getFormName($form);
    $s = bin2hex(random_bytes(32));
    $certs = isset($_SESSION["_cert"][$form]) ? $_SESSION["_cert"][$form] : [];
    if (!is_array($certs)) {
        $certs = [$certs];
    }
    $certs[] = $s;
    $_SESSION["_cert"][$form] = array_slice($certs, -5);
    if (10 < count($_SESSION["_cert"])) {
        array_shift($_SESSION["_cert"]);
    }
    return "<input name=\"__Cert\" value=\"" . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . "\" type=\"hidden\">";
}
public static function chkFormCert($s, $form = "")
{
    if (!isset($_SESSION) || !$s) {
        return false;
    }
    $form = self::getFormName($form);
    if (!isset($_SESSION["_cert"][$form])) {
        return false;
    }
    $certs = $_SESSION["_cert"][$form];
    if (!is_array($certs)) {
        $certs = [$certs];
    }
    foreach ($certs as $key => $cert) {
        if (hash_equals((string)$cert, (string)$s)) {
            unset($certs[$key]);
            if ($certs) {
                $_SESSION["_cert"][$form] = array_values($certs);
            } else {
                unset($_SESSION["_cert"][$form]);
            }
            return true;
        }
    }
    return false;
}
public static function checkFormSecurity($form = "")
{
    $form = self::getFormName($form);
    if (!self::chkFormCert(_IN("__Cert"), $form)) {
        xSysStop("Security: Wrong form certificate", true);
    }
    global $_IN;
    unset($_IN["__Cert"]);
    if (function_exists("chkCaptcha") && !chkCaptcha($form)) {
        self::setError("captcha_wrong", $form);
    }
}
public static function tplFormSecurity($form = "", $captcha = 0, $fallbackCaptcha = 0)
{
    [$form] = self::tplNormalizeFormSecurityParams($form, $captcha, $fallbackCaptcha);
    if (function_exists("getCaptcha")) {
        getCaptcha(0, $form);
    }
    return self::getFormCert($form);
}

public static function tplFormCaptcha($form = "", $captcha = 0, $fallbackCaptcha = 0)
{
    [$form, $captcha] = self::tplNormalizeFormSecurityParams($form, $captcha, $fallbackCaptcha);
    $capt = "";
    if (function_exists("getCaptcha")) {
        $capt = getCaptcha($captcha, $form);
    }
    self::setPage("__Capt", $capt, 0);
    return $capt;
}

}

?>
