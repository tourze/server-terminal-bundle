#!/usr/bin/env php
<?php

/**
 * 灵活的 SSH HTTP 轮询概念验证脚本
 * 
 * 支持多种连接方式：
 * 1. 真实的 SSH 连接（使用密码或密钥）
 * 2. 本地 shell 连接（作为备选方案）
 * 3. 模拟连接（用于演示）
 * 
 * 使用方法:
 * php ssh_poc_flexible.php --mode=ssh --host=10.211.55.47 --user=parallels --password=1234qwqw
 * php ssh_poc_flexible.php --mode=local
 * php ssh_poc_flexible.php --mode=mock
 */

declare(strict_types=1);

class FlexibleSshSessionManager
{
    private array $sessions = [];
    private array $processes = [];
    private string $mode = 'mock';

    public function __construct(string $mode = 'mock')
    {
        $this->mode = $mode;
    }

    public function createSession(string $host, string $user, ?string $password = null): string
    {
        $sessionId = bin2hex(random_bytes(8));
        
        switch ($this->mode) {
            case 'ssh':
                $success = $this->createSshSession($sessionId, $host, $user, $password);
                break;
            case 'local':
                $success = $this->createLocalSession($sessionId);
                break;
            case 'mock':
            default:
                $success = $this->createMockSession($sessionId, $host, $user);
                break;
        }
        
        if ($success) {
            echo "✅ 会话创建成功: {$sessionId} (模式: {$this->mode})\n";
            return $sessionId;
        }
        
        throw new RuntimeException("无法创建会话 (模式: {$this->mode})");
    }

    private function createSshSession(string $sessionId, string $host, string $user, ?string $password): bool
    {
        $command = $this->buildSshCommand($host, $user, $password);
        echo "🔌 尝试 SSH 连接: {$user}@{$host}\n";
        
        $process = $this->startProcess($command);
        if ($process) {
            $this->processes[$sessionId] = $process;
            $this->sessions[$sessionId] = [
                'type' => 'ssh',
                'host' => $host,
                'user' => $user,
                'created_at' => time(),
                'output_buffer' => "SSH 连接已建立到 {$user}@{$host}\n",
                'status' => 'active'
            ];
            return true;
        }
        
        echo "❌ SSH 连接失败，可能原因：\n";
        echo "   - 网络不通\n";
        echo "   - 服务器未响应\n";
        echo "   - 认证失败\n";
        echo "   - 防火墙阻拦\n";
        
        return false;
    }

    private function createLocalSession(string $sessionId): bool
    {
        echo "🏠 创建本地 Shell 会话\n";
        
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = proc_open('/bin/bash', $descriptors, $pipes);
        
        if (is_resource($process)) {
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            $this->processes[$sessionId] = [
                'resource' => $process,
                'stdin' => $pipes[0],
                'stdout' => $pipes[1],
                'stderr' => $pipes[2]
            ];
            
            $this->sessions[$sessionId] = [
                'type' => 'local',
                'host' => 'localhost',
                'user' => get_current_user(),
                'created_at' => time(),
                'output_buffer' => "本地 Shell 会话已创建\n",
                'status' => 'active'
            ];
            
            return true;
        }
        
        return false;
    }

    private function createMockSession(string $sessionId, string $host, string $user): bool
    {
        echo "🎭 创建模拟会话\n";
        
        $this->sessions[$sessionId] = [
            'type' => 'mock',
            'host' => $host,
            'user' => $user,
            'created_at' => time(),
            'output_buffer' => "模拟 SSH 会话已创建到 {$user}@{$host}\n",
            'status' => 'active',
            'mock_commands' => []
        ];
        
        return true;
    }

    public function sendCommand(string $sessionId, string $command): bool
    {
        if (!isset($this->sessions[$sessionId])) {
            return false;
        }
        
        $session = $this->sessions[$sessionId];
        echo "📤 发送命令: {$command}\n";
        
        switch ($session['type']) {
            case 'ssh':
            case 'local':
                return $this->sendRealCommand($sessionId, $command);
            case 'mock':
                return $this->sendMockCommand($sessionId, $command);
            default:
                return false;
        }
    }

    private function sendRealCommand(string $sessionId, string $command): bool
    {
        if (!isset($this->processes[$sessionId])) {
            return false;
        }
        
        $process = $this->processes[$sessionId];
        $input = $command . "\n";
        
        return fwrite($process['stdin'], $input) !== false;
    }

    private function sendMockCommand(string $sessionId, string $command): bool
    {
        $this->sessions[$sessionId]['mock_commands'][] = [
            'command' => $command,
            'time' => time()
        ];
        
        // 模拟延迟后生成输出
        $output = $this->generateMockOutput($command);
        $this->sessions[$sessionId]['output_buffer'] .= $output;
        
        return true;
    }

    public function readOutput(string $sessionId): string
    {
        if (!isset($this->sessions[$sessionId])) {
            return '';
        }
        
        $session = $this->sessions[$sessionId];
        
        switch ($session['type']) {
            case 'ssh':
            case 'local':
                return $this->readRealOutput($sessionId);
            case 'mock':
                return $this->readMockOutput($sessionId);
            default:
                return '';
        }
    }

    private function readRealOutput(string $sessionId): string
    {
        if (!isset($this->processes[$sessionId])) {
            return '';
        }
        
        $process = $this->processes[$sessionId];
        $output = '';
        
        while (($line = fgets($process['stdout'])) !== false) {
            $output .= $line;
        }
        
        while (($line = fgets($process['stderr'])) !== false) {
            $output .= '[STDERR] ' . $line;
        }
        
        if ($output) {
            $this->sessions[$sessionId]['output_buffer'] .= $output;
            echo "📥 收到输出 (" . strlen($output) . " 字节): " . trim($output) . "\n";
        }
        
        return $output;
    }

    private function readMockOutput(string $sessionId): string
    {
        // 模拟读取输出 - 大多数时候返回空
        if (rand(0, 100) < 20) {
            $mockOutputs = [
                "处理中...\n",
                "命令执行完成\n",
                "[" . date('H:i:s') . "] 系统消息\n",
                "OK\n"
            ];
            
            $output = $mockOutputs[array_rand($mockOutputs)];
            $this->sessions[$sessionId]['output_buffer'] .= $output;
            echo "📥 模拟输出: " . trim($output) . "\n";
            return $output;
        }
        
        return '';
    }

    private function generateMockOutput(string $command): string
    {
        switch (strtolower(trim($command))) {
            case 'ls':
            case 'ls -la':
                return "total 64\n" .
                       "drwxr-xr-x  2 parallels parallels 4096 Jan  1 12:00 .\n" .
                       "drwxr-xr-x  3 parallels parallels 4096 Jan  1 12:00 ..\n" .
                       "-rw-r--r--  1 parallels parallels  220 Jan  1 12:00 .bash_logout\n" .
                       "-rw-r--r--  1 parallels parallels 3526 Jan  1 12:00 .bashrc\n";
            case 'pwd':
                return "/home/parallels\n";
            case 'whoami':
                return "parallels\n";
            case 'date':
                return date('Y-m-d H:i:s') . "\n";
            case 'uname -a':
                return "Linux parallels-vm 5.4.0-42-generic #46-Ubuntu x86_64 GNU/Linux\n";
            default:
                if (strpos($command, 'echo') === 0) {
                    return substr($command, 5) . "\n";
                }
                return "模拟执行: {$command}\n执行完成\n";
        }
    }

    public function getSessionOutput(string $sessionId): string
    {
        return $this->sessions[$sessionId]['output_buffer'] ?? '';
    }

    public function closeSession(string $sessionId): bool
    {
        if (isset($this->processes[$sessionId])) {
            $process = $this->processes[$sessionId];
            
            fclose($process['stdin']);
            fclose($process['stdout']);
            fclose($process['stderr']);
            proc_terminate($process['resource']);
            proc_close($process['resource']);
            
            unset($this->processes[$sessionId]);
        }
        
        unset($this->sessions[$sessionId]);
        echo "🔒 会话已关闭: {$sessionId}\n";
        return true;
    }

    private function buildSshCommand(string $host, string $user, ?string $password): string
    {
        $command = "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -o ConnectTimeout=10 {$user}@{$host}";
        
        if ($password) {
            $escapedPassword = escapeshellarg($password);
            $command = "sshpass -p {$escapedPassword} {$command}";
        }
        
        return $command;
    }

    private function startProcess(string $command): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            return null;
        }
        
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        // 等待一下看连接是否成功
        sleep(2);
        
        // 检查进程状态
        $status = proc_get_status($process);
        if (!$status['running']) {
            return null;
        }
        
        return [
            'resource' => $process,
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2]
        ];
    }

    public function getSessionInfo(string $sessionId): ?array
    {
        return $this->sessions[$sessionId] ?? null;
    }
}

// 演示程序
function demonstrateFlexibleSsh(array $options): void
{
    $mode = $options['mode'] ?? 'mock';
    $host = $options['host'] ?? '10.211.55.47';
    $user = $options['user'] ?? 'parallels';
    $password = $options['password'] ?? null;
    
    echo "🚀 灵活 SSH HTTP 轮询概念验证\n";
    echo "模式: {$mode}\n";
    echo "===================================\n\n";
    
    $manager = new FlexibleSshSessionManager($mode);
    
    try {
        // 创建会话
        echo "📋 步骤 1: 创建会话\n";
        $sessionId = $manager->createSession($host, $user, $password);
        
        $sessionInfo = $manager->getSessionInfo($sessionId);
        echo "   会话信息: " . json_encode($sessionInfo, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";
        
        // 发送命令
        echo "📋 步骤 2: 发送命令\n";
        $commands = ['whoami', 'pwd', 'ls -la', 'date'];
        
        foreach ($commands as $command) {
            echo "\n--- 执行命令: {$command} ---\n";
            $manager->sendCommand($sessionId, $command);
            
            // 等待执行
            usleep(500000); // 500ms
            
            // 读取输出
            $manager->readOutput($sessionId);
            
            usleep(300000); // 300ms
        }
        
        // 模拟轮询
        echo "\n📋 步骤 3: 模拟轮询 (5秒)\n";
        $startTime = time();
        $pollCount = 0;
        
        while ((time() - $startTime) < 5) {
            $pollCount++;
            $output = $manager->readOutput($sessionId);
            
            if ($output) {
                echo "   [轮询 #{$pollCount}] 获得新输出\n";
            } else {
                echo "   [轮询 #{$pollCount}] 无输出\n";
            }
            
            usleep(1000000); // 1秒
        }
        
        // 显示完整输出
        echo "\n📋 步骤 4: 完整输出缓冲\n";
        echo $manager->getSessionOutput($sessionId);
        
        // 清理
        echo "\n📋 步骤 5: 清理会话\n";
        $manager->closeSession($sessionId);
        
        echo "\n✅ 演示完成！\n";
        
    } catch (Exception $e) {
        echo "❌ 演示失败: " . $e->getMessage() . "\n";
        
        if ($mode === 'ssh') {
            echo "\n💡 建议尝试其他模式：\n";
            echo "   php ssh_poc_flexible.php --mode=local  # 本地 Shell\n";
            echo "   php ssh_poc_flexible.php --mode=mock   # 模拟模式\n";
        }
    }
}

// 解析命令行参数
function parseArguments(array $argv): array
{
    $options = [];
    
    for ($i = 1; $i < count($argv); $i++) {
        $arg = $argv[$i];
        if (strpos($arg, '--') === 0) {
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? true;
            $options[$key] = $value;
        }
    }
    
    return $options;
}

// 显示帮助信息
function showHelp(): void
{
    echo "灵活的 SSH HTTP 轮询概念验证脚本\n\n";
    echo "用法:\n";
    echo "  php ssh_poc_flexible.php [选项]\n\n";
    echo "选项:\n";
    echo "  --mode=ssh|local|mock    连接模式 (默认: mock)\n";
    echo "  --host=HOST              SSH 主机地址\n";
    echo "  --user=USER              SSH 用户名\n";
    echo "  --password=PASS          SSH 密码\n";
    echo "  --help                   显示此帮助信息\n\n";
    echo "示例:\n";
    echo "  # SSH 连接模式\n";
    echo "  php ssh_poc_flexible.php --mode=ssh --host=10.211.55.47 --user=parallels --password=1234qwqw\n\n";
    echo "  # 本地 Shell 模式\n";
    echo "  php ssh_poc_flexible.php --mode=local\n\n";
    echo "  # 模拟模式（默认）\n";
    echo "  php ssh_poc_flexible.php --mode=mock\n\n";
}

// 主程序
function main(): void
{
    global $argv;
    
    $options = parseArguments($argv ?? []);
    
    if (isset($options['help'])) {
        showHelp();
        return;
    }
    
    demonstrateFlexibleSsh($options);
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "请在命令行环境中运行此脚本\n";
} 