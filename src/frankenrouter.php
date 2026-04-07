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

[$bbn, $routes, $cache, $cfg] = include_once __DIR__.'/bootstrap.php';
/** @var stdClass $bbn */
bbn\X::log('Frankenrouter loaded', 'frankenrouter');
// The current PID, is it unique?
define('BBN_PID', getmypid());
$workerId = bin2hex(random_bytes(3));
bbn\X::log("Worker boot: PID=" . getmypid() . " workerId=$workerId", 'frankenrouter-run');
$lastExec = time();
$handler = static function() use (&$routes, &$bbn, &$cfg, &$lastExec): void {
  try {
    if (isset($bbn->mvc)) {
      bbn\X::log('MVC exists', 'frankenrouter-run');
      exit(0);
    }
    if (!$bbn->db->check()) {
      bbn\X::log('DB Down', 'frankenrouter-run');
      exit(0);
    }
    $now = time();
    if (!isset($bbn->db)) {
      $bbn->db = new bbn\Db();
      $lastExec = $now;
    }
    elseif ($now - $lastExec > 3) {
      try {
        $bbn->db->query('SELECT 1');
        $lastExec = $now;
      }
      catch (Exception $e) {
        bbn\X::log('DB Down through real check', 'frankenrouter-run');
        $bbn->db->flush();
        $bbn->db->close();
        $bbn->db = new bbn\Db();
        try {
          $bbn->db->query('SELECT 1');
          $lastExec = $now;
        }
        catch (Exception $e) {
          bbn\X::log('DB Down after reconnect', 'frankenrouter-run');
          exit(0);
        }
      }
    }
    if (isset($bbn->session)) {
      $bbn->session->destruct();
    }

    $bbn->mvc = new bbn\Mvc($bbn->db, $routes);

    foreach ($routes['root'] as $url => $plugin) {
      if (!empty($plugin['static'])) {
        $bbn->mvc->addStaticRoute(...array_map(fn($a): string => $url . '/' . $a, $plugin['static']));
      }
    }

    /** @todo Make it depend of a constant from settings */
    bbn\Mvc::setDbInController(true);

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
    if (!$bbn->mvc->isStaticRoute()) {
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
        $sessCls = defined('BBN_SESSION') ? constant('BBN_SESSION') : '\\bbn\\User\\Session';
        if (!session_id() && defined("BBN_NO_REDIS")) {
          session_save_path($bbn->mvc->tmpPath() . 'sessions');
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
    }

    if (constant('BBN_IS_DEV')) {
      /*
      set_error_handler(function(int $errno, string $errstr) {
        throw new \Exception($errstr, $errno);
      }, E_WARNING);
      set_error_handler('\\bbn\\X::logError', E_ALL|~E_WARNING);
      set_exception_handler('\\bbn\\X::logException');
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

    // Routing
    if ($bbn->mvc->check()) {
      // Executing
      $bbn->mvc->process();
      /** @todo Why custom3 not in cli?? */
      if ($cfg['files']['custom3']) {
        include_once 'cfg/custom3.php';
      }
    }

    if (!empty($profiler)) {
      $profiler->finish($bbn->mvc);
    }

    // Outputs the result
    $bbn->mvc->output();


    /*
    if ($bbn->mvc->getConstant('mvc_id') && isset($bbn->db)) {
      $bbn->db->update(
        'bbn_mvc_logs',
        [
          'duration' => round($bbn->mvc->getTimer()->stop($bbn->mvc->getConstant('mvc_id')) * 1000),
        ],
        [
          'id' => $bbn->mvc->getConstant('mvc_id')
        ]
      );
    }*/
  }
  finally {
    if (isset($bbn->mvc)) {
      foreach (['ent', 'perm', 'pref', 'user', 'options'] as $key) {
        if (isset($bbn->mvc->inc->$key)) {
          $bbn->mvc->inc->$key->destruct();
        }
      }
  
      $bbn->mvc->destruct();
      unset($bbn->mvc);
    }
  
    if (isset($bbn->session)) {
      $bbn->session->destruct();
    }

    if (isset($bbn->db)) {
      $bbn->db->flush();
      $bbn->db->startFancyStuff();
    }

    gc_collect_cycles();
  }

};
bbn\X::log('Worker PID boot: ' . getmypid(), 'frankenrouter-run');

$i = 0;
while (frankenphp_handle_request($handler)) {
  $i++;
  bbn\X::log("Request #{$i} handled by worker $workerId" , 'frankenrouter-run');
}
