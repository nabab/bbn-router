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
  [$bbn, $routes, $cache, $cfg] = include_once __DIR__ . '/bootstrap.php';
  define('BBN_PID', getmypid());
  if (!is_file('cfg/.bbn/state.json')) {
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

  if (defined('BBN_DATABASE')) {
    $lastException = null;
    // Database
    try {
      $bbn->db = new bbn\Db();
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
          $bbn->db = new bbn\Db();
          if (!$state['db']) {
            $state['db'] = true;
            file_put_contents('cfg/.bbn/state.json', json_encode($state));
          }
          break;
        }
        catch (Exception $e) {
          $lastException = $e;
          continue;
        }
      }
    }

    if ($cache->check()) {
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
        if ($cache->check()) {
          $state['cache'] = true;
          file_put_contents('cfg/.bbn/state.json', json_encode($state));
          break;
        }
      }
    }

    if (!$state['db']) {
      throw $lastException ?: new Exception('Database connection failed without exception');
    }
    if (!$state['cache']) {
      throw new Exception('Cache connection failed');
    }

    $bbn->db->setTimezone(constant('BBN_TIMEZONE'));
  }
  exit(0);
})();
