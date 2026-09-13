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
[$bbn, $routes, $cache, $cfg] = include __DIR__.'/bootstrap.php';
bbn\X::log('Frankenrouter loaded', 'worker');
// The current PID, is it unique?
define('BBN_CACHE_CHECK_DELAY', 120);
define('BBN_PID', getmypid());
$workerId = bin2hex(random_bytes(3));
bbn\X::log("Worker boot: PID=" . getmypid() . " workerId=$workerId", 'frankenrouter-run');
$optCls = defined('BBN_OPTIONS') && class_exists(constant('BBN_OPTIONS')) ? constant('BBN_OPTIONS') : '\\bbn\\Appui\\Option';
$options = new $optCls($bbn->db);
$customFiles = $cfg['files'];
$data = [];
$timer = new bbn\Util\Timer();
$handler = static function() use (&$routes, &$bbn, &$customFiles, $cache, &$options, $defaults, $timer, &$data): void {
  try {
    if (isset($bbn->mvc)) {
      bbn\X::log('MVC exists', 'worker-run');
      return;
    }

    $timer->start('start');
    $i = 0;
    while (true) {
      bbn\X::log("Checking cache for the {$i}th time", 'worker-run');
      sleep(3);
      gc_collect_cycles();
      $i++;
    }
  }
  finally {
    $timer->stop('start');
    bbn\X::log($timer->results(), 'worker-run');
    $timer->resetAll();
  }
};
bbn\X::log('Worker PID boot: ' . getmypid(), 'worker-run');
