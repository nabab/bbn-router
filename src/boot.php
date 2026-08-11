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
  $db = null;
  $options = null;
  $mvc = null;
  $cron = null;
  while (true) {
    $cache = true;
    if (!is_dir('cfg/.bbn')) {
      mkdir('cfg/.bbn', 0755, true);
    }

    $fp = @fopen('cfg/.bbn/state.json', 'x');
    if ($fp !== false) {
      $state = [
        'db' => false,
        'cache' => false
      ];
      fwrite($fp, json_encode($state));
      fclose($fp);
    }
    else {
      $stateJson = file_get_contents('cfg/.bbn/state.json');
      $state = json_decode($stateJson, true);
      if (empty($state)) {
        $state = [
          'db' => false,
          'cache' => false
        ];
        file_put_contents('cfg/.bbn/state.json', json_encode($state));
      }
    }

    $cache = false;
    try {
      /** @var Cache The cache engine */
      $cache = Cache::getEngine();
    }
    catch (Exception $e) {
      // We set it in file just after
    }

    if ($cache && true) {
      if (!$state['cache']) {
        $state['cache'] = true;
        file_put_contents('cfg/.bbn/state.json', json_encode($state));
      }
    }
    else {
      if ($state['cache']) {
        $state['cache'] = false;
        file_put_contents('cfg/.bbn/state.json', json_encode($state));
      }
      for ($i = 0; $i < 30; $i++) {
        try {
          /** @var Cache The cache engine */
          $cache = Cache::getEngine();
          if ($cache->check()) {
            $state['cache'] = true;
            file_put_contents('cfg/.bbn/state.json', json_encode($state));
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
          $state['db'] = true;
          $options = new Option($db);
          $mvc = new Mvc($db, $routes);
          $cron = new Cron($db);
          file_put_contents('cfg/.bbn/state.json', json_encode($state));
        }
      }
      catch (Exception $e) {
        if (isset($db)) {
          $db->close();
          unset($db);
        }
        if ($state['db']) {
          $state['db'] = false;
          file_put_contents('cfg/.bbn/state.json', json_encode($state));
        }
  
        for ($i = 0; $i < 10; $i++) {
          sleep(1);
          try {
            $db = new Db();
            $db->rawQuery('SELECT 1');
            if (!$state['db']) {
              $state['db'] = true;
              $options = new Option($db);
              $mvc = new Mvc($db, $routes);
              $cron = new Cron($db);
              file_put_contents('cfg/.bbn/state.json', json_encode($state));
            }
            break;
          }
          catch (Exception $e) {
            if (isset($db)) {
              $db->close();
              unset($db);
            }
            continue;
          }
        }
      }
  
      if ($state['db'] && $state['cache']) {
        X::log('Database and cache are up and running for process ' . getmypid(), 'worker');
        if ($cron) {
          $has_active = is_file($cron->getStatusPath('active'));
          $has_cron = is_file($cron->getStatusPath('cron'));
          $has_poll = is_file($cron->getStatusPath('poll'));
          $crontime = false;
          $cronid = false;
          $polltime = false;
          $pollid = false;
          if (
            $has_cron
            && ($cronfile = $cron->getPidPath(['type' => 'cron']))
            && is_file($cronfile)
          ) {
            [$cronid, $crontime] = explode('|', File_get_contents($cronfile));
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
            [$pollid, $polltime] = explode('|', File_get_contents($pollfile));
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

          X::log([
            'has_active' => $has_active,
            'has_cron' => $has_cron,
            'has_poll' => $has_poll,
            'cronid' => $cronid,
            'crontime' => $crontime,
            'polltime' => $polltime,
            'pollid' => $pollid
          ], 'worker');
        }
        sleep(10);
      }
      else {
        X::log('Database and cache are not up nor running for process ' . getmypid(), 'worker');
        sleep(3);
      }
    }
  }
  exit(0);
})();
