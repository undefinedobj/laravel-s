<?php

namespace Hhxsv5\LaravelS\Illuminate;

use Illuminate\Console\Command;

class LaravelSCommand extends Command
{
    protected $signature = 'laravels {action? : publish|config|info}
    {--d|daemonize : Whether run as a daemon for "start & restart"}
    {--i|ignore : Whether ignore checking process pid for "start & restart"}';

    protected $description = 'LaravelS console tool';

    public function fire()
    {
        $this->handle();
    }

    public function handle()
    {
        $action = (string)$this->argument('action');
        switch ($action) {
            case 'publish':
                $this->publish();
                break;
            case 'config':
                $this->prepareConfig();
                break;
            case 'info':
                $this->showInfo();
                break;
            default:
                $this->info(sprintf('Usage: [%s] ./artisan laravels publish|config|info', PHP_BINARY));
                break;
        }
    }

    protected function isLumen()
    {
        return stripos($this->getApplication()->getVersion(), 'Lumen') !== false;
    }

    protected function loadConfig()
    {
        // Load configuration laravel.php manually for Lumen
        $basePath = config('laravels.laravel_base_path') ?: base_path();
        if ($this->isLumen() && file_exists($basePath . '/config/laravels.php')) {
            $this->getLaravel()->configure('laravels');
        }
    }

    protected function showInfo()
    {
        static $logo = <<<EOS
 _                               _  _____ 
| |                             | |/ ____|
| |     __ _ _ __ __ ___   _____| | (___  
| |    / _` | '__/ _` \ \ / / _ \ |\___ \ 
| |___| (_| | | | (_| |\ V /  __/ |____) |
|______\__,_|_|  \__,_| \_/ \___|_|_____/ 
                                           
EOS;
        $this->info($logo);
        $this->comment('Speed up your Laravel/Lumen');
        $laravelSVersion = '-';
        $cfg = json_decode(file_get_contents(base_path('composer.lock')), true);
        if (isset($cfg['packages'])) {
            foreach ($cfg['packages'] as $pkg) {
                if (isset($pkg['name']) && $pkg['name'] === 'hhxsv5/laravel-s') {
                    $laravelSVersion = ltrim($pkg['version'], 'vV');
                    break;
                }
            }
        }
        $this->table(['Component', 'Version'], [
            [
                'Component' => 'PHP',
                'Version'   => phpversion(),
            ],
            [
                'Component' => 'Swoole',
                'Version'   => swoole_version(),
            ],
            [
                'Component' => 'LaravelS',
                'Version'   => $laravelSVersion,
            ],
            [
                'Component' => $this->getApplication()->getName(),
                'Version'   => $this->getApplication()->getVersion(),
            ],
        ]);
    }

    protected function prepareConfig()
    {
        $this->loadConfig();

        $svrConf = config('laravels');

        $this->preSet($svrConf);

        $ret = $this->preCheck($svrConf);
        if ($ret !== 0) {
            return $ret;
        }

        $laravelConf = [
            'root_path'          => $svrConf['laravel_base_path'],
            'static_path'        => $svrConf['swoole']['document_root'],
            'register_providers' => array_unique((array)array_get($svrConf, 'register_providers', [])),
            'is_lumen'           => $this->isLumen(),
            '_SERVER'            => $_SERVER,
            '_ENV'               => $_ENV,
        ];

        $config = ['server' => $svrConf, 'laravel' => $laravelConf];
        $config = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents(base_path('storage/laravels.json'), $config);
        return 0;
    }

    protected function preSet(array &$svrConf)
    {
        if (!isset($svrConf['enable_gzip'])) {
            $svrConf['enable_gzip'] = false;
        }
        if (empty($svrConf['laravel_base_path'])) {
            $svrConf['laravel_base_path'] = base_path();
        }
        if (empty($svrConf['process_prefix'])) {
            $svrConf['process_prefix'] = $svrConf['laravel_base_path'];
        }
        if ($this->option('ignore')) {
            $svrConf['ignore_check_pid'] = true;
        } elseif (!isset($svrConf['ignore_check_pid'])) {
            $svrConf['ignore_check_pid'] = false;
        }
        if (empty($svrConf['swoole']['document_root'])) {
            $svrConf['swoole']['document_root'] = $svrConf['laravel_base_path'] . '/public';
        }
        if ($this->option('daemonize')) {
            $svrConf['swoole']['daemonize'] = true;
        } elseif (!isset($svrConf['swoole']['daemonize'])) {
            $svrConf['swoole']['daemonize'] = false;
        }
        if (empty($svrConf['swoole']['pid_file'])) {
            $svrConf['swoole']['pid_file'] = storage_path('laravels.pid');
        }
        return 0;
    }

    protected function preCheck(array $svrConf)
    {
        if (!empty($svrConf['enable_gzip']) && version_compare(swoole_version(), '4.1.0', '>=')) {
            $this->error('enable_gzip is DEPRECATED since Swoole 4.1.0, set http_compression of Swoole instead, http_compression is disabled by default.');
            $this->info('If there is a proxy server like Nginx, suggest that enable gzip in Nginx and disable gzip in Swoole, to avoid the repeated gzip compression for response.');
            return 1;
        }
        if (!empty($svrConf['events'])) {
            if (empty($svrConf['swoole']['task_worker_num']) || $svrConf['swoole']['task_worker_num'] <= 0) {
                $this->error('Asynchronous event listening needs to set task_worker_num > 0');
                return 1;
            }
        }
        return 0;
    }


    public function publish()
    {
        $basePath = config('laravels.laravel_base_path') ?: base_path();
        $configPath = $basePath . '/config/laravels.php';
        $todoList = [
            ['from' => realpath(__DIR__ . '/../../config/laravels.php'), 'to' => $configPath, 'mode' => 0644],
            ['from' => realpath(__DIR__ . '/../../bin/laravels'), 'to' => $basePath . '/bin/laravels', 'mode' => 0755, 'link' => true],
            ['from' => realpath(__DIR__ . '/../../bin/fswatch'), 'to' => $basePath . '/bin/fswatch', 'mode' => 0755, 'link' => true],
        ];
        if (file_exists($configPath)) {
            $choice = $this->anticipate($configPath . ' already exists, do you want to override it ? Y/N',
                ['Y', 'N'],
                'N'
            );
            if (!$choice || strtoupper($choice) !== 'Y') {
                array_shift($todoList);
            }
        }

        foreach ($todoList as $todo) {
            $toDir = dirname($todo['to']);
            if (!is_dir($toDir)) {
                mkdir($toDir, 0755, true);
            }
            if (file_exists($todo['to'])) {
                unlink($todo['to']);
            }
            $operation = 'Copied';
            if (empty($todo['link'])) {
                copy($todo['from'], $todo['to']);
            } else {
                if (@link($todo['from'], $todo['to'])) {
                    $operation = 'Linked';
                } else {
                    copy($todo['from'], $todo['to']);
                }

            }
            chmod($todo['to'], $todo['mode']);
            $this->line("<info>{$operation} file</info> <comment>[{$todo['from']}]</comment> <info>To</info> <comment>[{$todo['to']}]</comment>");
        }
        return 0;
    }
}
