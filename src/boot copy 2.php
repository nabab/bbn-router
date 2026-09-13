<?php
use bbn\X;
use bbn\Mvc;
use bbn\Cache;
use bbn\Db;
use bbn\Cron;
use bbn\Appui\Option;
(function() {
  /**
   * @var stdClass $bbn
   * @var array $routes
   * @var Cache $cache
   * @var array $cfg
   */
  [$bbn, $routes, $cfg] = include_once __DIR__ . '/bootstrap.php';
  define('BBN_PID', getmypid());
  set_error_handler('\\bbn\\X::logError', E_ALL);
  set_exception_handler('\\bbn\\X::logException');
  $db = null;
  $options = null;
  $mvc = null;
  $cron = null;
  $socketLaunched = false;
  if (!is_dir('cfg/.bbn')) {
    mkdir('cfg/.bbn', 0755, true);
  }

  if (file_exists(Mvc::getTmpPath() . 'logs/_php_error.lock')) {
    unlink(Mvc::getTmpPath() . 'logs/_php_error.lock');
  }


  while (true) {
    $fp = @fopen('cfg/.bbn/state.json', 'x');
    if ($fp !== false) {
      $state = [
        'db' => false,
        'cache' => false
      ];
      $stateFile = $state;
      fwrite($fp, json_encode($stateFile));
      fclose($fp);
    }
    else {
      $stateJson = file_get_contents('cfg/.bbn/state.json');
      $stateFile = json_decode($stateJson, true);
      $state = [
        'db' => false,
        'cache' => false
      ];
    }

    $cache = false;
    try {
      /** @var Cache The cache engine */
      $cache = Cache::getEngine();
      if (!$cache->getObj()) {
        $cache = false;
      }
    }
    catch (Exception $e) {
      // We set it in file just after
    }

    if ($cache) {
      if (!$stateFile['cache']) {
        $stateFile['cache'] = true;
        file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
      }
      if (!$state['cache']) {
        $state['cache'] = true;
      }
    }
    else {
      if ($stateFile['cache']) {
        $stateFile['cache'] = false;
        file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
      }
      if ($state['cache']) {
        $state['cache'] = false;
      }

      for ($i = 0; $i < 30; $i++) {
        try {
          /** @var Cache The cache engine */
          $cache = Cache::getEngine();
          if ($cache->getObj()) {
            if (!$stateFile['cache']) {
              $stateFile['cache'] = true;
              file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
            }
            if (!$state['cache']) {
              $state['cache'] = true;
            }
            break;
          }
        }
        catch (Exception $e) {
          sleep(1);
        }
      }
    }
  
    if ($state['cache'] && defined('BBN_DATABASE')) {
      // Database
      try {
        $db = $db ?: new Db();
        $db->rawQuery('SELECT 1');
        if (!$state['db']) {
          if (!$stateFile['db']) {
            $stateFile['db'] = true;
            file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
          }
          $state['db'] = true;
          $options = $options ?? new Option($db);
          $mvc = $mvc ?? new Mvc($db, $routes);
          $cron = $cron ?? new Cron($db);
        }
      }
      catch (Exception $e) {
        if (isset($db)) {
          $db->close();
          unset($db, $options, $mvc, $cron);
        }
        if ($state['db']) {
          $state['db'] = false;
          $stateFile['db'] = false;
          file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
        }
  
        for ($i = 0; $i < 10; $i++) {
          sleep(1);
          try {
            $db = new Db();
            $db->rawQuery('SELECT 1');
            if (!$state['db']) {
              if (!$stateFile['db']) {
                $stateFile['db'] = true;
                file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
              }
              $state['db'] = true;
              $options = $options ?? new Option($db);
              $mvc = $mvc ?? new Mvc($db, $routes);
              $cron = $cron ?? new Cron($db);
            }
            break;
          }
          catch (Exception $e) {
            if (isset($db)) {
              $db->close();
              unset($db, $options, $mvc, $cron);
            }
            continue;
          }
        }
      }
  
      if ($state['db'] && $state['cache']) {
        if ($cron) {
          // First go
          if (!$socketLaunched) {
            // Error lock file
            X::log('about to launch socket', 'socket-start');
            /*
            $pidPath = dirname($cron->getPidPath(['type' => 'cron']));
            foreach (glob($pidPath . '/*.pid') as $file) {
              if (is_file($file)) {
                unlink($file);
              }
            }

            $cron->launchSocketServer();
            */
            $socketLaunched = true;
          }

          $has_active = is_file($cron->getStatusPath('active'));
          $has_cron = is_file($cron->getStatusPath('cron'));
          $has_poll = is_file($cron->getStatusPath('poll'));
          $crontime = false;
          $cronid = false;
          $polltime = false;
          $pollid = false;
          X::log([
            'has_active' => $has_active,
            'has_cron' => $has_cron,
            'has_poll' => $has_poll,
            'crontime' => $crontime,
            'cronid' => $cronid,
            'polltime' => $polltime,
            'pollid' => $pollid,
            'cronfile' => $cron->getPidPath(['type' => 'cron']),
            'pollfile' => $cron->getPidPath(['type' => 'poll']),
            'pidcronfile' => is_file($cron->getPidPath(['type' => 'cron'])),
            'pidpollfile' => is_file($cron->getPidPath(['type' => 'poll']))
          ], 'worker');
          if (
            $has_cron
            && ($cronfile = $cron->getPidPath(['type' => 'cron']))
            && is_file($cronfile)
          ) {
            [$cronid, $crontime] = explode('|', file_get_contents($cronfile));
            if (!file_exists('/proc/'.$cronid)) {
              unlink($cronfile);
              $crontime = false;
              $cronid = false;
            }
          }
          if (
            $has_poll
            && ($pollfile = $cron->getPidPath(['type' => 'poll']))
            && is_file($pollfile)
          ){
            [$pollid, $polltime] = explode('|', file_get_contents($pollfile));
            if (!file_exists('/proc/'.$pollid)) {
              unlink($pollfile);
              $polltime = false;
              $pollid = false;
            }
          }

          if ($has_active) {
            if ($has_poll && !$pollid) {
              $cron->launchPoll();
              $polltime = time();
            }
            if ($has_cron && !$cronid) {
              $cron->launchTaskSystem();
              $crontime = time();
            }
          }

          /*
          X::log([
            'has_active' => $has_active,
            'has_cron' => $has_cron,
            'has_poll' => $has_poll,
            'cronid' => $cronid,
            'crontime' => $crontime,
            'polltime' => $polltime,
            'pollid' => $pollid
          ], 'worker');*/
        }
        else {
          X::log('No cron object for process ' . getmypid(), 'worker');
        }

        sleep(10);
      }
      else {
        X::log('Database and cache are not up nor running for process ' . getmypid(), 'socket-start');
        sleep(3);
      }
    }

    //echo "Finished cycle" . PHP_EOL;
    sleep(1);
  }
})();
