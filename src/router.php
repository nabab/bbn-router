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
if (!isset($installer)) {
  $installer = null;
}

(function ($installer) {
  [$bbn, $routes, $cfg] = include_once __DIR__.'/bootstrap.php';
  set_error_handler('\\bbn\\X::logError', E_ALL);
  set_exception_handler('\\bbn\\X::logException');

  try {
    $cache = bbn\Cache::getEngine();
  }
  catch (Exception $e) {
    bbn\X::log('Cache Down at start of request', 'router-error');
    exit("...");
  }

  if (!defined('BBN_DATABASE')) {
    // No database
    $bbn->db = false;
  } else {
    $lastException = null;
    // Database
    try {
      $bbn->db = new bbn\Db();
    }
    catch (Exception $e) {
      for ($i = 0; $i < 10; $i++) {
        sleep(1);
        try {
          $bbn->db = new bbn\Db();
          break;
        }
        catch (Exception $e) {
          continue;
        }
      }
      sleep(3);
      try {
        $bbn->db = new bbn\Db();
      }
      catch (Exception $e) {
        bbn\X::logException($e);
        $lastException = $e;
      }
    }

    if (!$bbn->db) {
      throw $lastException ?: new Exception('Database connection failed without exception');
    }

    $bbn->db->setTimezone(constant('BBN_TIMEZONE'));
  }

  if ($installer && file_exists('cfg/init.php')) {
    include_once 'cfg/init.php';
  }

  $bbn->mvc = new bbn\Mvc($bbn->db, $routes);

  foreach ($routes['root'] as $url => $plugin) {
    if (!empty($plugin['static'])) {
      $bbn->mvc->addStaticRoute(...array_map(fn($a): string => $url . '/' . $a, $plugin['static']));
    }
  }

  /** @todo Make it depend of a constant from settings */
  bbn\Mvc::setDbInController(true);

  // The current PID, is it unique?
  define('BBN_PID', getmypid());
  // Setting up options
  if (defined('BBN_OPTIONS') && ($optCls = constant('BBN_OPTIONS'))) {
    $optCls = is_string($optCls) && class_exists($optCls) ? $optCls : '\\bbn\\Appui\\Option';
    $bbn->mvc->addInc(
      'options',
      new $optCls($bbn->db)
    );
  }


  // Loading users scripts before session is set (but it is started)
  if ($cfg['files']['custom1']) {
    include_once 'cfg/custom1.php';
  }

  // CLI
  if (!$bbn->mvc->isStaticRoute($bbn->mvc->getRequest())) {
    if (!$bbn->is_cli) {
      if ($cfg['files']['session']) {
        $default = file_get_contents('cfg/session.json');
        if ($default && ($default = json_decode($default, true))) {
          $defaults = array_merge($bbn->vars['default_session'], $default);
        }
      }

      if (empty($defaults)) {
        $defaults = $bbn->vars['default_session'];
      }

      if (defined('BBN_USER') && ($userCls = constant('BBN_USER'))) {
        if (session_id()) {
          throw new Exception('A session is already started, impossible to start another one');
        }

        $sessCls = defined('BBN_SESSION') ? constant('BBN_SESSION') : '\\bbn\\User\\Session';
        if (defined("BBN_NO_REDIS") && constant('BBN_NO_REDIS')) {
          session_save_path($bbn->mvc->tmpPath() . 'sessions');
        }
        else {
          ini_set('session.save_handler', 'redis');
          $host = defined('BBN_CACHE_HOST') ? constant('BBN_CACHE_HOST') : 'redis';
          ini_set('session.save_path', 'tcp://' . $host . ':6379?database=2&prefix=PHPSESSID:');
        }

        $bbn->session = new $sessCls($defaults);
        $bbn->mvc->addInc('session', $bbn->session);
        $userCls = is_string($userCls) && class_exists($userCls) ? $userCls : '\\bbn\\User';
        $bbn->mvc->addInc(
          'user',
          new $userCls(
            $bbn->db,
            $bbn->mvc->getPost()
          )
        );

        if (defined('BBN_PREFERENCES') && ($prefCls = constant('BBN_PREFERENCES'))) {
          $prefCls = is_string($prefCls) && class_exists($prefCls) ? $prefCls : '\\bbn\\User\\Preferences';
          $bbn->mvc->addInc('pref', new $prefCls($bbn->db));
        }

        if (defined('BBN_PERMISSIONS') && ($permCls = constant('BBN_PERMISSIONS'))) {
          $permCls = is_string($permCls) && class_exists($permCls) ? $permCls : '\\bbn\\User\\Permissions';
          $bbn->mvc->addInc('perm', new $permCls($routes));
        }

        if (defined('BBN_HISTORY') && ($histCls = constant('BBN_HISTORY'))) {
          $histCls = is_string($histCls) && class_exists($histCls) ? $histCls : '\\bbn\\Appui\\History';
          $histCls::init(
            $bbn->db,
            // User
            ['user' => $bbn->mvc->inc->user->getId() ?: (defined('BBN_EXTERNAL_USER_ID') ? constant('BBN_EXTERNAL_USER_ID') : null)],
          );
        }
      }

      if ($cfg['files']['custom2']) {
        include_once 'cfg/custom2.php';
      }
    } elseif (defined('BBN_PREFERENCES') && ($prefCls = constant('BBN_PREFERENCES')) && ($userCls = constant('BBN_USER')) && defined('BBN_EXTERNAL_USER_ID')) {
      // Setting up user
      $userCls = is_string($userCls) && class_exists($userCls) ? $userCls : '\\bbn\\User';
      $bbn->mvc->addInc(
        'user',
        new $userCls(
          $bbn->db,
          ['id' => BBN_EXTERNAL_USER_ID]
        )
      );
      $prefCls = is_string($prefCls) && class_exists($prefCls) ? $prefCls : '\\bbn\\User\\Preferences';
      $bbn->mvc->addInc('pref', new $prefCls($bbn->db));
      // Setting up history
      if (defined('BBN_HISTORY') && ($histCls = constant('BBN_HISTORY'))) {
        $histCls = is_string($histCls) && class_exists($histCls) ? $histCls : '\\bbn\\Appui\\History';
        $histCls::init(
          $bbn->db,
          // User adhérent
          ['user' => BBN_EXTERNAL_USER_ID]
        );
      }
    }
  }

  if (constant('BBN_IS_DEV')) {
    // Warning becomes exception in dev

    // Adding profiling if true or is current url or starts like url if finishes with a *
    /** @var bool Becomes profiler object if profiling is activated */
    $profiler = false;
    $prof = defined('BBN_PROFILING') ? constant('BBN_PROFILING') : false;
    if (($prof === true)
      || (is_string($prof)
        && (($bbn->mvc->getUrl() === $prof)
          || ((substr($prof, -1) === '*')
            && (strpos($bbn->mvc->getUrl(), substr($prof, 0, -1)) === 0)
          )
        )
      )
    ) {
      $profiler = new bbn\Appui\Profiler($bbn->db);
      $profiler->start();
    }
  }

  define('BBN_LAUNCH_TIME', microtime(true));

  // Routing
  if ($bbn->mvc->check()) {
    // Executing
    $bbn->mvc->process();
    if ($bbn->is_cli) {
      //file_put_contents(BBN_DATA_PATH.'cli.txt', "0");
    }
    /** @todo Why custom3 not in cli?? */
    elseif ($cfg['files']['custom3']) {
      include_once 'cfg/custom3.php';
    }
  }

  if (!empty($profiler)) {
    $profiler->finish($bbn->mvc);
  }

  // Outputs the result
  $bbn->mvc->output();

  if ($bbn->mvc->getConstant('mvc_id') && isset($bbn->db)) {
    //bbn\X::ddump($bbn->mvc->getTimer()->hasStarted($bbn->mvc->getConstant('mvc_id')), $bbn->mvc->getTimer()->results(), $bbn->mvc->getConstant('mvc_id'));
    $bbn->db->update(
      'bbn_mvc_logs',
      [
        'duration' => round((method_exists($bbn->mvc, 'getTimer') ? $bbn->mvc->getTimer()->result($bbn->mvc->getConstant('mvc_id'))['average'] : $bbn->mvc->getTimer()->result($bbn->mvc->getConstant('mvc_id'))['average']) * 1000),
      ],
      [
        'id' => $bbn->mvc->getConstant('mvc_id')
      ]
    );
  }

})($installer);
