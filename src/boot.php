<?php
use bbn\X;
use bbn\Cache;
(function() {
  /**
   * @var stdClass $bbn
   * @var array $routes
   * @var Cache $cache
   * @var array $cfg
   */
  [$bbn, $routes, $cfg] = include_once __DIR__ . '/bootstrap.php';
  define('BBN_PID', getmypid());
  while (true) {
    $cache = true;
    if (!is_dir('cfg/.bbn')) {
      mkdir('cfg/.bbn', 0755, true);
    }

    if (!file_exists('cfg/.bbn/state.json')) {
      $state = [
        'db' => false,
        'cache' => false
      ];
      file_put_contents('cfg/.bbn/state.json', json_encode($state));
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
      /** @var bbn\Cache The cache engine */
      $cache = bbn\Cache::getEngine();
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
        sleep(1);
        try {
          /** @var bbn\Cache The cache engine */
          $cache = bbn\Cache::getEngine();
          if ($cache->check()) {
            $state['cache'] = true;
            file_put_contents('cfg/.bbn/state.json', json_encode($state));
            break;
          }
        }
        catch (Exception $e) {
        }
      }
    }
  
    if ($state['cache'] && defined('BBN_DATABASE')) {
      // Database
      try {
        $db = new bbn\Db();
        $db->rawQuery('SELECT 1');
        $db->close();
        if (!$state['db']) {
          $state['db'] = true;
          file_put_contents('cfg/.bbn/state.json', json_encode($state));
        }
      }
      catch (Exception $e) {
        if ($state['db']) {
          $state['db'] = false;
          file_put_contents('cfg/.bbn/state.json', json_encode($state));
        }
  
        for ($i = 0; $i < 30; $i++) {
          sleep(1);
          try {
            $db = new bbn\Db();
            $db->rawQuery('SELECT 1');
            if (!$state['db']) {
              $state['db'] = true;
              file_put_contents('cfg/.bbn/state.json', json_encode($state));
            }
            break;
          }
          catch (Exception $e) {
            continue;
          }
          finally {
            if (isset($db)) {
              $db->close();
              unset($db);
            }
          }
        }
      }
      finally {
        if (isset($db)) {
          $db->close();
          unset($db);
        }
      }
  
      if ($state['db'] && $state['cache']) {
        sleep(10);
      }
      else {
        sleep(3);
      }
    }
  }
  exit(0);
})();
