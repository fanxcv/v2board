<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Protocols\V2rayN;
use App\Http\Controllers\Controller;
use App\Services\ServerService;
use Illuminate\Http\Request;
use App\Services\UserService;

class ClientController extends Controller
{
    public function subscribe(Request $request)
    {
        $flag = $request->input('flag')
            ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $flag = strtolower($flag);
        $user = $request->user;
        // account not expired and is not banned.
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $serverService = new ServerService();
            $servers = $serverService->getAvailableServers($user);
            $servers = $this->extractServers($servers);
            $this->setSubscribeInfoToServers($servers, $user);
            if ($flag) {
                foreach (glob(app_path('Http//Controllers//Client//Protocols') . '/*.php') as $file) {
                    $file = 'App\\Http\\Controllers\\Client\\Protocols\\' . basename($file, '.php');
                    $class = new $file($user, $servers);
                    if (strpos($flag, $class->flag) !== false) {
                        die($class->handle());
                    }
                }
            }
            // todo 1.5.3 remove
            $class = new V2rayN($user, $servers);
            die($class->handle());
            die('该客户端暂不支持进行订阅');
        }
    }

    private function extractServers($servers) {
        $map = $this->createHostMap($servers);

        return array_merge([], ...array_map(function ($server) use ($map) {
            $hosts = explode(',', $this->findHostMap($map, $server['host']));

            // 为空的情况直接丢弃
            if (empty($hosts)) {
                return [];
            }

            // 是否需要在名称后面添加索引
            $skipMarkIndex = count($hosts) < 2;

            return array_map(function ($host, $idx) use ($server, $skipMarkIndex) {
                $copy = unserialize(serialize($server));
                $host_arr = explode(':', $host);
                if (count($host_arr) == 1) {
                    $copy['host'] = $host_arr[0];
                } elseif (count($host_arr) == 2) {
                    $copy['host'] = $host_arr[0];
                    $copy['port'] = intval($host_arr[1];
                } else {
                    $copy['host'] = $host;
                }
                if (!$skipMarkIndex) {
                    // $copy['name'] = join(' - ', [$copy['name'], $idx + 1]);
                    $copy['name'] = join(' - ', [$copy['name'], $host]);
                }
                return $copy;
            }, $hosts, array_keys($hosts));
        }, $servers));
    }

    private function createHostMap($servers)
    {
        return array_combine(
            array_map(function ($item) {
                return $item['id'];
            }, $servers),
            array_map(function ($item) {
                return $item['host'];
            }, $servers)
        );
    }

    private function findHostMap($map, $pattern) {
        if (str_starts_with($pattern, '=')) {
            $key = substr($pattern, 1);
            if (isset($map[$key])) {
                return $this->findHostMap($map, $map[$key]);
            } else {
                return '';
            }
        }
        return $pattern;
    }
    
    private function setSubscribeInfoToServers(&$servers, $user)
    {
        if (!(int)config('v2board.show_info_to_server_enable', 0)) return;
        $useTraffic = round($user['u'] / (1024*1024*1024), 2) + round($user['d'] / (1024*1024*1024), 2);
        $totalTraffic = round($user['transfer_enable'] / (1024*1024*1024), 2);
        $remainingTraffic = $totalTraffic - $useTraffic;
        $expiredDate = $user['expired_at'] ? date('Y-m-d', $user['expired_at']) : '长期有效';
        $userService = new UserService();
        $resetDay = $userService->getResetDay($user);
        array_unshift($servers, array_merge($servers[0], [
            'name' => "套餐到期：{$expiredDate}",
        ]));
        if ($resetDay) {
            array_unshift($servers, array_merge($servers[0], [
                'name' => "距离下次重置剩余：{$resetDay} 天",
            ]));
        }
        array_unshift($servers, array_merge($servers[0], [
            'name' => "剩余流量：{$remainingTraffic} GB",
        ]));
    }
}
