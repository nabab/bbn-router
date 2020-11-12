<?php

/**
 * This file deals with all the requests (users and API calls).
 *
 * Long description for file (if any)...
 *
 * - It should be auto-generated
 * - All these constants are mandatory.
 * - Deleting a line might crash the app.
 *
 * @category   CategoryName
 *
 * @author     Thomas Nabet <thomas.nabet@gmail.com>
 * @copyright  2008-2020 BBN Solutions
 * @license    http://www.php.net/license/3_01.txt  PHP License 3.01
 *
 * @version    SVN: $Id$
 *
 * @see       http://pear.php.net/package/PackageName
 * @see        mvc
 */
(function ($installer) {
  $timings = false;
  @ini_set('zlib.output_compression', 'off');
  /** The only/main object */
  $bbn = new stdClass();
  $bbn->is_cli = php_sapi_name() === 'cli';
  $app_path = dirname(getcwd()).'/';
  //$app_path = __DIR__.'/';
  //chdir($app_path);
  if (function_exists('yaml_parse') && file_exists('cfg/environment.yml') && ($tmp = file_get_contents('cfg/environment.yml'))) {
    $cfgs = yaml_parse($tmp);
  }
  elseif (function_exists('json_decode') && file_exists('cfg/environment.json') && ($tmp = file_get_contents('cfg/environment.json'))) {
    $cfgs = json_decode($tmp, true);
  }
  if (empty($cfgs)) {
    die("No environment files in $app_path    ".getcwd());
  }
  $hostname = gethostname();
  foreach ($cfgs as $c) {
    if (isset($c['hostname']) && ($c['hostname'] === $hostname) && ($c['app_path'] === $app_path)) {
      /** @var array $cfg */
      $cfg = $c;
      break;
    }
  }
  if (!isset($cfg)) {
    die('No parameter corresponding to the current configuration.');
  }

  // Redirection to https in case of SSL
  if (!$bbn->is_cli
      && !empty($cfg['is_ssl'])
      && ($_SERVER['REQUEST_SCHEME'] === 'http')
  ) {
    header('Location: https://'.$cfg['server_name'].$_SERVER['REQUEST_URI']);
    exit;
  }

  $tmp = false;
  if (function_exists('yaml_parse') && file_exists('cfg/settings.yml') && ($tmp = file_get_contents('cfg/settings.yml'))) {
    $tmp = yaml_parse($tmp);
  }
  elseif (function_exists('json_decode') && file_exists('cfg/settings.json') && ($tmp = file_get_contents('cfg/settings.json'))) {
    $tmp = json_decode($tmp, true);
  }
  if (!$tmp) {
    die('impossible to read the configuration file (settings.json).');
  }
  $cfg = array_merge($cfg, $tmp);

  foreach ($cfg as $n => $c) {
    if ($n === 'env') {
      define('BBN_IS_DEV', $c === 'dev');
      define('BBN_IS_TEST', $c === 'test');
      define('BBN_IS_PROD', $c === 'prod');
    }
    /* @constant string BBN_SERVER_NAME The server's name as in the app's URL */
    /* @constant BBN_CUR_PATH */
    define('BBN_' . strtoupper($n), $c);
  }
  if (!defined('BBN_IS_SSL')) {
    define('BBN_IS_SSL', false);
  }
  if (!defined('BBN_PORT')) {
    define('BBN_PORT', BBN_IS_SSL ? 443 : 80);
  }

  // Creating the URL
  $tmp = 'http';
  if (BBN_IS_SSL) {
    $tmp .= 's';
  }
  $tmp .= '://' . BBN_SERVER_NAME;
  if (BBN_PORT && (BBN_PORT != 80) && (BBN_PORT != 443)) {
    $tmp .= ':' . BBN_PORT;
  }
  if (BBN_CUR_PATH) {
    $tmp .= BBN_CUR_PATH;
    if (substr(BBN_CUR_PATH, -1) !== '/') {
      $tmp .= '/';
    }
  }
  define('BBN_URL', $tmp);

  if (!$bbn->is_cli && ($_SERVER['SERVER_NAME'] !== BBN_SERVER_NAME)) {
    header('Location: ' . BBN_URL);
  }
  // Adding profiling
  /*
  if (BBN_IS_DEV) {
    include BBN_PUBLIC.'../xhgui/external/header.php';
  }
  */
  if (!defined('BBN_APP_PREFIX') && defined('BBN_APP_NAME')) {
    define('BBN_APP_PREFIX', BBN_APP_NAME);
  }

  if (!defined('BBN_LIB_PATH')
      || !defined('BBN_APP_PATH')
      || !defined('BBN_DATA_PATH')
      || !defined('BBN_APP_NAME')
      || !defined('BBN_TIMEZONE')
      || !defined('BBN_SESS_LIFETIME')
      || !defined('BBN_PUBLIC')
      || !defined('BBN_IS_DEV')
  ) {
    die('Sorry check your config file or rebuild it, all the necessaries variable are not there.');
  }

  // Classes autoloaders
  spl_autoload_register(
    function ($class_name) {
      if ((strpos($class_name, '/') === false) && (strpos($class_name, '.') === false)) {
        $cls = explode('\\', $class_name);
        $path = implode('/', $cls);
        if (file_exists(BBN_APP_PATH . 'src/lib/' . $path . '.php')) {
          include_once BBN_APP_PATH . 'src/lib/' . $path . '.php';
        }
      }
    }
  );
  include BBN_LIB_PATH . 'autoload.php';
  if ($timings) {
    $chrono = new \bbn\util\timer();
    $chrono->start();
  }

  // This application is in utf8
  mb_internal_encoding('UTF-8');

  // The default timezome of the site (before finding out about the user's timezone
  date_default_timezone_set(BBN_TIMEZONE);

  ini_set('error_log', BBN_DATA_PATH . 'logs/_php_error.log');
  set_error_handler('\\bbn\\x::log_error', E_ALL);

  $cache = \bbn\cache::get_engine('files');
  if ($cache_cfg = $cache->get('cfg_files')) {
    $cfg_files = $cache_cfg;
  }
  else {
    $cfg_files = [
      'custom1' => file_exists('cfg/custom1.php'),
      'custom2' => file_exists('cfg/custom2.php'),
      'custom3' => file_exists('cfg/custom3.php'),
      'session' => file_exists('cfg/session.json'),
      'end' => file_exists('cfg/end.php')
    ];
    $cache->set('cfg_files', $cfg_files, 600);
  }
  if ($timings) {
    \bbn\x::log(['config file', $chrono->measure()], 'timings');
  }
  $routes = false;
  // How to find out the default locale formating ?
  if (defined('BBN_LANG') && !defined('BBN_LOCALE')) {
    $locales = [
      BBN_LANG . '_' . strtoupper(BBN_LANG) . '.utf8',
      BBN_LANG . '-' . strtoupper(BBN_LANG) . '.utf8',
      BBN_LANG . '_' . strtoupper(BBN_LANG),
      BBN_LANG . '-' . strtoupper(BBN_LANG),
      BBN_LANG,
    ];
    foreach ($locales as $l) {
      if (setlocale(LC_TIME, $l)) {
        define('BBN_LOCALE', $l);
        break;
      }
    }
    if (!defined('BBN_LOCALE')) {
      $locales = [
        'en_EN.utf8',
        'en-EN.utf8',
        'en_EN',
        'en-EN',
        'en_US.utf8',
        'en-US.utf8',
        'en_US',
        'en-US',
        'en',
      ];
      foreach ($locales as $l) {
        if (setlocale(LC_TIME, $l)) {
          define('BBN_LOCALE', $l);
          break;
        }
      }
    }
  }

  $bbn->vars = [
    'default_session' => [
      'path' => BBN_CUR_PATH,
      'history' => [],
    ],
  ];

  // Loading routes configuration
  if (BBN_IS_DEV) {
    bbn\mvc::debug();
  }
  if (function_exists('yaml_parse') && file_exists('cfg/routes.yml') && ($tmp = file_get_contents('cfg/routes.yml'))) {
    $routes = yaml_parse($tmp);
  }
  elseif (function_exists('json_decode') && file_exists('cfg/routes.json') && ($tmp = file_get_contents('cfg/routes.json'))) {
    $routes = json_decode($tmp, true);
  }

  if ($installer && file_exists('cfg/init.php')) {
    include_once 'cfg/init.php';
  }

  if (!defined('BBN_DATABASE') || (BBN_DATABASE === '')) {
    $bbn->db = false;
    $bbn->dbs = [];
  }
  else {
    $bbn->db = new bbn\db();
    $bbn->dbs = [&$bbn->db];
  }

  if ($timings) {
    \bbn\x::log(['DB', $chrono->measure()], 'timings');
  }
  $bbn->mvc = new bbn\mvc($bbn->db, $routes ?: []);

  if ($timings) {
    \bbn\x::log(['MVC', $chrono->measure()], 'timings');
  }
  bbn\mvc::set_db_in_controller(true);

  define('BBN_PID', getmypid());

  if (defined('BBN_OPTIONS') && BBN_OPTIONS) {
    $options_cls = is_string(BBN_OPTIONS) && class_exists(BBN_OPTIONS) ? BBN_OPTIONS : '\\bbn\\appui\\options';
    $bbn->mvc->add_inc(
      'options',
      new $options_cls($bbn->db)
    );
  }
  // Loading users scripts before session is set (but it is started)
  if ($cfg_files['custom1']) {
    include_once 'cfg/custom1.php';
  }

  // CLI
  if (!$bbn->is_cli) {
    if ($cfg_files['session']) {
      $default = file_get_contents('cfg/session.json');
      if ($default) {
        $default = json_decode($default, true);
        if (is_array($default)) {
          $defaults = array_merge($bbn->vars['default_session'], $default);
        }
      }
    }
    else {
      $defaults = $bbn->vars['default_session'];
    }
    if (defined('BBN_USER') && BBN_USER) {
      $session_cls = defined('BBN_SESSION') && is_string(BBN_SESSION) && class_exists(BBN_SESSION) ?
        BBN_SESSION : '\\bbn\\user\\session';
      $bbn->session = new $session_cls($defaults);
      $bbn->mvc->add_inc('session', $bbn->session);
      $user_cls = is_string(BBN_USER) && class_exists(BBN_USER) ?
        BBN_USER : '\\bbn\\user';
      $bbn->mvc->add_inc(
        'user',
        new $user_cls(
          $bbn->db,
          $bbn->mvc->get_post()
        )
      );

      if (defined('BBN_PREFERENCES') && BBN_PREFERENCES) {
        $pref_cls = is_string(BBN_PREFERENCES) && class_exists(BBN_PREFERENCES) ?
          BBN_PREFERENCES : '\\bbn\\user\\preferences';
        $bbn->mvc->add_inc('pref', new $pref_cls($bbn->db));
      }

      if (defined('BBN_PERMISSIONS') && BBN_PERMISSIONS) {
        $perm_cls = is_string(BBN_PERMISSIONS) && class_exists(BBN_PERMISSIONS) ?
          BBN_PERMISSIONS : '\\bbn\\user\\permissions';
        $bbn->mvc->add_inc('perm', new $perm_cls());
      }
      if (defined('BBN_HISTORY')) {
        \bbn\appui\history::init(
          $bbn->db,
          // User adhérent
          ['user' => $bbn->mvc->inc->user->get_id()]
        );
      }
    }
    if ($cfg_files['custom2']) {
      include_once 'cfg/custom2.php';
    }
  }
  elseif (defined('BBN_USER') && BBN_USER && defined('BBN_EXTERNAL_USER_ID')) {
    $user_cls = is_string(BBN_USER) && class_exists(BBN_USER) ?
      BBN_USER : '\\bbn\\user';
    $bbn->mvc->add_inc(
      'user',
      new $user_cls(
        $bbn->db,
        ['id' => BBN_EXTERNAL_USER_ID]
      )
    );
    if (defined('BBN_HISTORY')) {
      \bbn\appui\history::init(
        $bbn->db,
        // User adhérent
        ['user' => BBN_EXTERNAL_USER_ID]
      );
    }
  }
  if ($timings) {
    \bbn\x::log(['All set up', $chrono->measure()], 'timings');
  }

  if ($bbn->mvc->check()) {
    if ($timings) {
      \bbn\x::log(['checked', $chrono->measure()], 'timings');
    }
    /*
    die(var_dump(
      $bbn->mvc->get_url(),
      $bbn->mvc->get_file(),
      $bbn->mvc->get_files(),
      $bbn->mvc->inc->user->check(),
      $bbn->mvc->_controller
    ));
    */
    $bbn->mvc->process();
    if ($timings) {
      \bbn\x::log(['processed', $chrono->measure()], 'timings');
    }
    if ($bbn->is_cli) {
      //file_put_contents(BBN_DATA_PATH.'cli.txt', "0");
    }
    elseif ($cfg_files['custom3']) {
      include_once 'cfg/custom3.php';
    }
    if ($timings) {
      \bbn\x::log(['custom 3', $chrono->measure()], 'timings');
    }
  }
  $bbn->mvc->output();
  if ($timings) {
    \bbn\x::log(['output', $chrono->measure()], 'timings');
  }
})($installer ?? null);
