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

  /** @todo Not sure why... */
  @ini_set('zlib.output_compression', 'off');
  /** The only/main object */
  $bbn = new stdClass();
  $bbn->is_cli = php_sapi_name() === 'cli';
  $errorFn = function($msg) use (&$bbn){
    $st = sprintf('
The following error occurred: %s.

In order to repair or redo your installation you need to download the following script:
<a href="https://app-ui.com/download/bbn-install.php">bbn-install.php</a>
and put it in the public root of your web server.
', $msg);
  if ($bbn->is_cli) {
    die(nl2br($st));
  }

  die($st);
  };
  /** @var string Current directory which MUST be the root of the project where the symlink to rhis file is located */
  $app_path = dirname(getcwd()).'/';
  // Parsing YAML environment's configuration
  if (function_exists('yaml_parse')
      && file_exists('cfg/environment.yml')
      && ($tmp = file_get_contents('cfg/environment.yml'))
  ) {
    /** @var array Environment's configuration */
    $cfgs = yaml_parse($tmp);
  }
  // Or parsing JSON environment's configuration
  elseif (function_exists('json_decode')
      && file_exists('cfg/environment.json')
      && ($tmp = file_get_contents('cfg/environment.json'))
  ) {
    /** @var array ENvironment's configuration */
    $cfgs = json_decode($tmp, true);
  }

  // If no readable environment's configuration is found the app is not configured correctly
  if (empty($cfgs)) {
    $errorFn("No environment files in $app_path    ".getcwd());
  }

  /** @var string The hostname */
  $hostname = gethostname();
  // Checking each configuration
  foreach ($cfgs as $c) {
    // Looking for the corresponding hostname and app path
    if (isset($c['hostname']) && ($c['hostname'] === $hostname) && ($c['app_path'] === $app_path)) {
      if (!empty($c['force_server_name'])) {
        if (!empty($c['server_name'])
            && ($c['server_name'] === $_SERVER['SERVER_NAME'])
        ) {
          $cfg = $c;
          break;
        }
      }
      else {
        /** @var array The current configuration */
        $cfg = $c;
        break;
      }
    }
  }

  // If no corresponding configuration is found the app is not configured correctly
  if (!isset($cfg)) {
    $errorFn('No parameter corresponding to the current configuration.');
  }

  // Redirection to https in case of SSL configuration
  if (!$bbn->is_cli
      && !empty($cfg['is_ssl'])
      && ($_SERVER['REQUEST_SCHEME'] === 'http')
  ) {
    header('Location: https://'.$cfg['server_name'].$_SERVER['REQUEST_URI']);
    exit;
  }

  /** @var mixed Temporary variable for the general settings, which should be an array */
  $tmp = false;
  if (function_exists('yaml_parse') && file_exists('cfg/settings.yml') && ($tmp = file_get_contents('cfg/settings.yml'))) {
    $tmp = yaml_parse($tmp);
  }
  elseif (function_exists('json_decode') && file_exists('cfg/settings.json') && ($tmp = file_get_contents('cfg/settings.json'))) {
    $tmp = json_decode($tmp, true);
  }

  // If no general setting is found the app is not configured correctly
  if (!$tmp) {
    $errorFn('impossible to read the configuration file (settings.json).');
  }

  // The cfg array becomes a mix of current environment and settings
  $cfg = array_merge($cfg, $tmp);

  // Each value in thew array will define a constant with prefix BBN_
  foreach ($cfg as $n => $c) {
    if ($n === 'spec') {
      continue;
    }
    if ($n === 'env') {
      define('BBN_IS_DEV', $c === 'dev');
      define('BBN_IS_TEST', $c === 'test');
      define('BBN_IS_PROD', $c === 'prod');
    }

    /* @constant string BBN_SERVER_NAME The server's name as in the app's URL */
    /* @constant BBN_CUR_PATH */
    define('BBN_' . strtoupper($n), $c);
  }

  // Is SSL is false by default
  /** @todo change it? */
  if (!defined('BBN_IS_SSL')) {
    define('BBN_IS_SSL', false);
  }

  // Default web port
  if (!defined('BBN_PORT')) {
    define('BBN_PORT', BBN_IS_SSL ? 443 : 80);
  }

  /** The base URL of the application */
  $url = 'http'
      .(BBN_IS_SSL ? 's' : '')
      .'://' . BBN_SERVER_NAME
      .(BBN_PORT && !in_array(BBN_PORT, [80, 443]) ? ':'.BBN_PORT : '')
      .(BBN_CUR_PATH ? BBN_CUR_PATH : '');
  if (substr($url, -1) !== '/') {
    $url .= '/';
  }

  define('BBN_URL', $url);

  // If the server name is different the request is redirected
  if (!$bbn->is_cli && ($_SERVER['SERVER_NAME'] !== BBN_SERVER_NAME)) {
    header('Location: ' . BBN_URL);
  }

  // In case app_prefix isn't defined we use app_name
  if (!defined('BBN_APP_PREFIX') && defined('BBN_APP_NAME')) {
    define('BBN_APP_PREFIX', BBN_APP_NAME);
  }

  if (isset($cfg['spec'])) {
    foreach ($cfg['spec'] as $key => $val) {
      define(strtoupper(BBN_APP_PREFIX).'_'.strtoupper($key), $val);
    }
  }

  // Checking all the necessary constants are defined... or die
  if (!defined('BBN_LIB_PATH')
      || !defined('BBN_APP_PATH')
      || !defined('BBN_DATA_PATH')
      || !defined('BBN_APP_NAME')
      || !defined('BBN_TIMEZONE')
      || !defined('BBN_SESS_LIFETIME')
      || !defined('BBN_PUBLIC')
      || !defined('BBN_IS_DEV')
  ) {
    $errorFn('Sorry check your config file or rebuild it, all the necessaries variable are not there.');
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

  /** @var bool If set to true will log execution timings of the router */
  $timings = !!(defined('BBN_TIMINGS') && BBN_TIMINGS);
  // If timing
  if ($timings) {
    $chrono = new bbn\Util\Timer();
    $chrono->start();
  }

  // This application is in utf8
  mb_internal_encoding('UTF-8');

  // The default timezome of the site (before finding out about the user's timezone
  date_default_timezone_set(BBN_TIMEZONE);

  ini_set('error_log', BBN_DATA_PATH . 'logs/_php_error.log');
  //set_error_handler('\\bbn\\X::log_error', E_ALL);

  /** @var bbn\Cache The cache engine */
  $cache = bbn\Cache::getEngine('files');
  
  // Setting the custom files presence in cache
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
    bbn\X::log(['config file', $chrono->measure()], 'timings');
  }

  /** @todo default session info, I don't see the point */
  $bbn->vars = [
    'default_session' => [
      'path' => BBN_CUR_PATH,
      'history' => [],
    ],
  ];

  if (BBN_IS_DEV) {
    bbn\Mvc::debug();
  }

  // Loading routes configuration
  if (function_exists('yaml_parse') && file_exists('cfg/routes.yml') && ($tmp = file_get_contents('cfg/routes.yml'))) {
    $routes = yaml_parse($tmp);
  }
  elseif (function_exists('json_decode') && file_exists('cfg/routes.json') && ($tmp = file_get_contents('cfg/routes.json'))) {
    $routes = json_decode($tmp, true);
  }
  else {
    $routes = [];
  }

  if ($installer && file_exists('cfg/init.php')) {
    include_once 'cfg/init.php';
  }

  if (!defined('BBN_DATABASE') || (BBN_DATABASE === '')) {
    // No database
    $bbn->db = false;
    $bbn->dbs = [];
  }
  else {
    // Database
    $bbn->db = new bbn\Db();
    $bbn->dbs = [&$bbn->db];
  }

  if ($timings) {
    bbn\X::log(['DB', $chrono->measure()], 'timings');
  }

  $bbn->mvc = new bbn\Mvc($bbn->db, $routes);

  if ($timings) {
    bbn\X::log(['MVC', $chrono->measure()], 'timings');
  }

  /** @todo Make it depend of a constant from settings */
  bbn\Mvc::setDbInController(true);

  // The current PID, is it unique?
  define('BBN_PID', getmypid());

  // Setting up options
  if (defined('BBN_OPTIONS') && BBN_OPTIONS) {
    $options_cls = is_string(BBN_OPTIONS) && class_exists(BBN_OPTIONS) ? BBN_OPTIONS : '\\bbn\\Appui\\Option';
    $bbn->mvc->addInc(
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
      if ($default && ($default = json_decode($default, true))) {
        $defaults = array_merge($bbn->vars['default_session'], $default);
      }
    }

    if (empty($defaults)) {
      $defaults = $bbn->vars['default_session'];
    }

    if (defined('BBN_USER') && BBN_USER) {
      $session_cls = defined('BBN_SESSION') && is_string(BBN_SESSION) && class_exists(BBN_SESSION) ?
	      BBN_SESSION : '\\bbn\\User\\Session';
      session_save_path(BBN_DATA_PATH . 'sessions');
      $bbn->session = new $session_cls($defaults);
      $bbn->mvc->addInc('session', $bbn->session);
      $user_cls = is_string(BBN_USER) && class_exists(BBN_USER) ?
        BBN_USER : '\\bbn\\User';
      $bbn->mvc->addInc(
        'user',
        new $user_cls(
          $bbn->db,
          $bbn->mvc->getPost()
        )
      );

      if (defined('BBN_PREFERENCES') && BBN_PREFERENCES) {
        $pref_cls = is_string(BBN_PREFERENCES) && class_exists(BBN_PREFERENCES) ?
          BBN_PREFERENCES : '\\bbn\\User\\Preferences';
        $bbn->mvc->addInc('pref', new $pref_cls($bbn->db));
      }

      if (defined('BBN_PERMISSIONS') && BBN_PERMISSIONS) {
        $perm_cls = is_string(BBN_PERMISSIONS) && class_exists(BBN_PERMISSIONS) ?
          BBN_PERMISSIONS : '\\bbn\\User\\Permissions';
        $bbn->mvc->addInc('perm', new $perm_cls($routes));
      }

      if (defined('BBN_HISTORY') && BBN_HISTORY) {
        bbn\Appui\History::init(
          $bbn->db,
          // User
          ['user' => $bbn->mvc->inc->user->getId() ?: BBN_EXTERNAL_USER_ID]
        );
      }
    }

    if ($cfg_files['custom2']) {
      include_once 'cfg/custom2.php';
    }
  }
  elseif (defined('BBN_USER') && BBN_USER && defined('BBN_EXTERNAL_USER_ID')) {
    // Setting up user
    $user_cls = is_string(BBN_USER) && class_exists(BBN_USER) ?
      BBN_USER : '\\bbn\\User';
    $bbn->mvc->addInc(
      'user',
      new $user_cls(
        $bbn->db,
        ['id' => BBN_EXTERNAL_USER_ID]
      )
    );
    // Setting up history
    if (defined('BBN_HISTORY')) {
      bbn\Appui\History::init(
        $bbn->db,
        // User adhérent
        ['user' => BBN_EXTERNAL_USER_ID]
      );
    }
  }

  if ($timings) {
    bbn\X::log(['All set up', $chrono->measure()], 'timings');
  }

  /** @var bool Becomes true if profiling is activated */
  $profiler = false;
  // Adding profiling if true or is current url or starts like url if finishes with a *
  if (BBN_IS_DEV
      && defined('BBN_PROFILING') && (
        (BBN_PROFILING === true)
        || (is_string(BBN_PROFILING) 
          && (($bbn->mvc->getUrl() === BBN_PROFILING)
          || ((substr(BBN_PROFILING, -1) === '*')
            && (strpos($bbn->mvc->getUrl(), substr(BBN_PROFILING, 0, -1)) === 0)
          )
        )
      )
    )
  ) {
    $profiler = new bbn\Appui\Profiler($bbn->db);
    $profiler->start();
  }

  // Routing
  if ($bbn->mvc->check()) {
    if ($timings) {
      bbn\X::log(['checked', $chrono->measure()], 'timings');
    }

    // Executing
    $bbn->mvc->process();

    if ($timings) {
      bbn\X::log(['processed', $chrono->measure()], 'timings');
    }

    if ($bbn->is_cli) {
      //file_put_contents(BBN_DATA_PATH.'cli.txt', "0");
    }
    /** @todo Why custom3 not in cli?? */
    elseif ($cfg_files['custom3']) {
      include_once 'cfg/custom3.php';
    }

    if ($timings) {
      bbn\X::log(['custom 3', $chrono->measure()], 'timings');
    }
  }

  if ($profiler) {
    $profiler->finish($bbn->mvc);
  }

  // Outputs the result
  $bbn->mvc->output();

  if ($timings) {
    bbn\X::log(['output', $chrono->measure()], 'timings');
  }

})($installer ?? null);
