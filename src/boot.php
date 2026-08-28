<?php
use bbn\X;
use bbn\Mvc;
use bbn\Cache;
use bbn\Db;
use bbn\Cron;
use bbn\Appui\Option;

set_time_limit(0);

(function() {
    /**
     * @var stdClass $bbn
     * @var array $routes
     * @var Cache $cache
     * @var array $cfg
     */
    
    
    [$bbn, $routes, $cfg] = include_once __DIR__ . '/bootstrap.php';
    X::log('boot: start', 'boot');
    define('BBN_PID', getmypid());
    set_error_handler('\\bbn\\X::logError', E_ALL);
    set_exception_handler('\\bbn\\X::logException');
    
    X::log('boot: bootstrap loaded, PID=' . BBN_PID, 'boot');

    $db = null;
    $options = null;
    $mvc = null;
    $cron = null;
    $socketLaunched = false;
    
    if (!is_dir('cfg/.bbn')) {
        mkdir('cfg/.bbn', 0755, true);
        X::log('boot: created cfg/.bbn directory', 'boot');
    }

    if (file_exists(Mvc::getTmpPath() . 'logs/_php_error.lock')) {
        unlink(Mvc::getTmpPath() . 'logs/_php_error.lock');
        X::log('boot: removed _php_error.lock', 'boot');
    }

    while (true) {
        X::log('loop: start iteration', 'boot');
        
        $fp = @fopen('cfg/.bbn/state.json', 'x');
        if ($fp !== false) {
            $state = [
                'db' => false,
                'cache' => false
            ];
            $stateFile = $state;
            fwrite($fp, json_encode($stateFile));
            fclose($fp);
            X::log('loop: created new state.json', 'boot');
        } else {
            $stateJson = file_get_contents('cfg/.bbn/state.json');
            $stateFile = json_decode($stateJson, true);
            $state = [
                'db' => false,
                'cache' => false
            ];
            X::log('loop: loaded existing state.json', 'boot');
        }

        try {
            /** @var Cache The cache engine */
            if (empty($cache)) {
              $cache = new Cache();
            }

            if (!$cache->getObj()) {
                $cache = false;
                X::log('loop: cache engine returned no object', 'boot');
            } else {
                X::log('loop: cache engine connected successfully', 'boot');
            }
        } catch (Exception $e) {
            X::log('loop: exception getting cache engine: ' . $e->getMessage(), 'boot');
        }

        if ($cache) {
            if (!$stateFile['cache']) {
                $stateFile['cache'] = true;
                file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
                X::log('loop: stateFile cache set to true', 'boot');
            }
            if (!$state['cache']) {
                $state['cache'] = true;
                X::log('loop: state cache set to true', 'boot');
            }
        } else {
            if ($stateFile['cache']) {
                $stateFile['cache'] = false;
                file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
                X::log('loop: stateFile cache set to false (was true)', 'boot');
            }
            if ($state['cache']) {
                $state['cache'] = false;
                X::log('loop: state cache set to false (was true)', 'boot');
            }

            for ($i = 0; $i < 30; $i++) {
                try {
                    /** @var Cache The cache engine */
                    $cache = new Cache();
                    if ($cache->getObj()) {
                        X::log("loop: retry $i succeeded, cache connected", 'boot');
                        if (!$stateFile['cache']) {
                            $stateFile['cache'] = true;
                            file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
                        }
                        if (!$state['cache']) {
                            $state['cache'] = true;
                        }
                        break;
                    } else {
                        X::log("loop: retry $i failed, no cache object", 'boot');
                        sleep(2);
                    }
                } catch (Exception $e) {
                    X::log("loop: retry $i exception: " . $e->getMessage(), 'boot');
                    sleep(2);
                }
            }
        }

        if ($state['cache'] && defined('BBN_DATABASE')) {
            // Database
            try {
                $db = $db ?: new Db();
                $db->rawQuery('SELECT 1');
                
                X::log('loop: DB connection successful', 'boot');
                
                if (!$state['db']) {
                    if (!$stateFile['db']) {
                        $stateFile['db'] = true;
                        file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
                        X::log('loop: stateFile db set to true', 'boot');
                    }
                    $state['db'] = true;
                    X::log('loop: state db set to true', 'boot');
                    
                    $options = $options ?? new Option($db);
                    $mvc = $mvc ?? new Mvc($db, $routes);
                    $cron = $cron ?? new Cron($db);
                    X::log('loop: initialized options, mvc, cron objects', 'boot');
                }
            } catch (Exception $e) {
                X::log('loop: DB exception: ' . $e->getMessage(), 'boot');
                
                if (isset($db)) {
                    $db->close();
                    unset($db, $options, $mvc, $cron);
                    X::log('loop: closed and unset db objects', 'boot');
                }
                if ($state['db']) {
                    $state['db'] = false;
                    $stateFile['db'] = false;
                    file_put_contents('cfg/.bbn/state.json', json_encode($stateFile));
                    X::log('loop: state db set to false due to exception', 'boot');
                }
                
                for ($i = 0; $i < 10; $i++) {
                    sleep(1);
                    try {
                        $db = new Db();
                        $db->rawQuery('SELECT 1');
                        
                        X::log("loop: DB retry $i successful", 'boot');
                        
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
                    } catch (Exception $e) {
                        X::log("loop: DB retry $i exception: " . $e->getMessage(), 'boot');
                        if (isset($db)) {
                            $db->close();
                            unset($db, $options, $mvc, $cron);
                        }
                        continue;
                    }
                }
            }

            if ($state['db'] && $state['cache']) {
                X::log('loop: both DB and cache are up', 'boot');
                
                if ($cron) {
                    // First go
                    if (!$socketLaunched) {
                        X::log('about to launch socket', 'socket-start');
                        
                        $pidPath = dirname($cron->getPidPath(['type' => 'cron']));
                        foreach (glob($pidPath . '/*.pid') as $file) {
                            if (is_file($file)) {
                                unlink($file);
                            }
                        }
                        
                        X::log('boot: launching socket server', 'boot');
                        $cron->launchSocketServer();
                        $socketLaunched = true;
                        X::log('boot: socket server launched', 'boot');
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
                        if (!file_exists('/proc/' . $cronid)) {
                            unlink($cronfile);
                            $crontime = false;
                            $cronid = false;
                            X::log('boot: removed stale cron pid file', 'boot');
                        }
                    }

                    if (
                        $has_poll
                        && ($pollfile = $cron->getPidPath(['type' => 'poll']))
                        && is_file($pollfile)
                    ) {
                        [$pollid, $polltime] = explode('|', file_get_contents($pollfile));
                        if (!file_exists('/proc/' . $pollid)) {
                            unlink($pollfile);
                            $polltime = false;
                            $pollid = false;
                            X::log('boot: removed stale poll pid file', 'boot');
                        }
                    }

                    if ($has_active) {
                        if ($has_poll && !$pollid) {
                            X::log('boot: launching poll', 'boot');
                            $cron->launchPoll();
                            $polltime = time();
                        }
                        if ($has_cron && !$cronid) {
                            X::log('boot: launching task system (cron)', 'boot');
                            $cron->launchTaskSystem();
                            $crontime = time();
                        }
                    } else {
                        X::log('boot: no active status file, skipping launch', 'boot');
                    }

                } else {
                    X::log('No cron object for process ' . getmypid(), 'worker');
                    X::log('boot: $cron is null/falsy despite db/cache being up', 'boot');
                }

            } else {
                X::log('Database and cache are not up nor running for process ' . getmypid(), 'socket-start');
                X::log("boot: state db=" . var_export($state['db'], true) . " cache=" . var_export($state['cache'], true), 'boot');
            }
        } else {
            X::log('boot: skipping DB block. cache=' . var_export($state['cache'], true) . ', BBN_DATABASE defined=' . (defined('BBN_DATABASE') ? 'yes' : 'no'), 'boot');
        }
    }
})();
