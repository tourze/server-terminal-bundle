#!/usr/bin/env php
<?php

/**
 * SSH HTTP 轮询概念验证脚本
 *
 * 这个脚本演示了如何使用 PHP 进程管理来实现 SSH 连接，
 * 并模拟 HTTP 轮询的工作方式。
 *
 * 使用方法:
 * php ssh_poc.php
 */

declare(strict_types=1);

// 模拟会话存储
class SessionStorage
{
    private array $sessions = [];

    public function create(string $sessionId, array $data): void
    {
        $this->sessions[$sessionId] = array_merge($data, [
            'created_at' => time(),
            'last_activity' => time(),
            'output_buffer' => '',
            'status' => 'active'
        ]);
    }

    public function get(string $sessionId): ?array
    {
        return $this->sessions[$sessionId] ?? null;
    }

    public function update(string $sessionId, array $data): void
    {
        if (isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = array_merge($this->sessions[$sessionId], $data);
            $this->sessions[$sessionId]['last_activity'] = time();
        }
    }

    public function delete(string $sessionId): void
    {
        unset($this->sessions[$sessionId]);
    }

    public function getExpiredSessions(int $maxIdleTime): array
    {
        $expired = [];
        $now = time();
        
        foreach ($this->sessions as $id => $session) {
            if (($now - $session['last_activity']) > $maxIdleTime) {
                $expired[] = $id;
            }
        }
        
        return $expired;
    }
}

// SSH 会话管理器
class SshSessionManager
{
    private SessionStorage $storage;
    private array $processes = [];

    public function __construct(SessionStorage $storage)
    {
        $this->storage = $storage;
    }

    public function createSession(string $host, string $user, ?string $password = null): string
    {
        $sessionId = $this->generateSessionId();
        
        // 创建 SSH 命令
        $command = $this->buildSshCommand($host, $user, $password);
        
        // 启动进程
        $process = $this->startProcess($command);
        
        if ($process) {
            $this->processes[$sessionId] = $process;
            $this->storage->create($sessionId, [
                'host' => $host,
                'user' => $user,
                'command' => $command
            ]);
            
            echo "✅ SSH 会话已创建: {$sessionId}\n";
            echo "   连接到: {$user}@{$host}\n";
            return $sessionId;
        }
        
        throw new RuntimeException('无法创建 SSH 连接');
    }

    public function sendCommand(string $sessionId, string $command): bool
    {
        $session = $this->storage->get($sessionId);
        if (!$session || !isset($this->processes[$sessionId])) {
            return false;
        }

        $process = $this->processes[$sessionId];
        
        // 写入命令到进程
        $input = $command . "\n";
        if (fwrite($process['stdin'], $input) === false) {
            return false;
        }
        
        echo "📤 发送命令: {$command}\n";
        return true;
    }

    public function readOutput(string $sessionId): string
    {
        $session = $this->storage->get($sessionId);
        if (!$session || !isset($this->processes[$sessionId])) {
            return '';
        }

        $process = $this->processes[$sessionId];
        $output = '';
        
        // 非阻塞读取输出
        while (($line = fgets($process['stdout'])) !== false) {
            $output .= $line;
        }
        
        if ($output) {
            // 更新会话缓冲区
            $newBuffer = $session['output_buffer'] . $output;
            // 限制缓冲区大小 (64KB)
            if (strlen($newBuffer) > 65536) {
                $newBuffer = substr($newBuffer, -65536);
            }
            
            $this->storage->update($sessionId, [
                'output_buffer' => $newBuffer
            ]);
            
            echo "📥 收到输出: " . trim($output) . "\n";
        }
        
        return $output;
    }

    public function getSessionOutput(string $sessionId): string
    {
        $session = $this->storage->get($sessionId);
        return $session['output_buffer'] ?? '';
    }

    public function closeSession(string $sessionId): bool
    {
        if (isset($this->processes[$sessionId])) {
            $process = $this->processes[$sessionId];
            
            // 关闭管道
            fclose($process['stdin']);
            fclose($process['stdout']);
            fclose($process['stderr']);
            
            // 终止进程
            proc_terminate($process['resource']);
            proc_close($process['resource']);
            
            unset($this->processes[$sessionId]);
        }
        
        $this->storage->delete($sessionId);
        echo "🔒 会话已关闭: {$sessionId}\n";
        return true;
    }

    public function cleanupExpiredSessions(int $maxIdleTime = 1800): void // 30分钟
    {
        $expiredIds = $this->storage->getExpiredSessions($maxIdleTime);
        
        foreach ($expiredIds as $sessionId) {
            echo "🧹 清理过期会话: {$sessionId}\n";
            $this->closeSession($sessionId);
        }
    }

    private function generateSessionId(): string
    {
        return hash('sha256', uniqid('ssh_', true) . random_bytes(16));
    }

    private function buildSshCommand(string $host, string $user, ?string $password): string
    {
        // 基础 SSH 命令，添加更多选项以提高兼容性
        $command = "ssh -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR {$user}@{$host}";
        
        if ($password) {
            // 使用 sshpass 进行密码认证
            // 注意：sshpass 需要预先安装 (brew install sshpass 或 apt-get install sshpass)
            $escapedPassword = escapeshellarg($password);
            $command = "sshpass -p {$escapedPassword} {$command}";
        }
        
        return $command;
    }

    private function startProcess(string $command): ?array
    {
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout  
            2 => ['pipe', 'w']  // stderr
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            return null;
        }
        
        // 设置非阻塞模式
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        return [
            'resource' => $process,
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2]
        ];
    }
}

// 模拟 HTTP API 控制器
class SshApiController
{
    private SshSessionManager $sessionManager;

    public function __construct(SshSessionManager $sessionManager)
    {
        $this->sessionManager = $sessionManager;
    }

    public function createSession(array $params): array
    {
        $host = $params['host'] ?? '';
        $user = $params['user'] ?? '';
        $password = $params['password'] ?? null;
        
        if (empty($host) || empty($user)) {
            return ['error' => '主机和用户名不能为空'];
        }
        
        try {
            $sessionId = $this->sessionManager->createSession($host, $user, $password);
            return ['session_id' => $sessionId, 'status' => 'created'];
        } catch (Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    public function sendCommand(array $params): array
    {
        $sessionId = $params['session_id'] ?? '';
        $command = $params['command'] ?? '';
        
        if (empty($sessionId) || empty($command)) {
            return ['error' => '会话ID和命令不能为空'];
        }
        
        $success = $this->sessionManager->sendCommand($sessionId, $command);
        return ['success' => $success];
    }

    public function pollOutput(array $params): array
    {
        $sessionId = $params['session_id'] ?? '';
        
        if (empty($sessionId)) {
            return ['error' => '会话ID不能为空'];
        }
        
        // 读取新输出
        $newOutput = $this->sessionManager->readOutput($sessionId);
        
        // 获取完整输出缓冲
        $fullOutput = $this->sessionManager->getSessionOutput($sessionId);
        
        return [
            'new_output' => $newOutput,
            'full_output' => $fullOutput,
            'timestamp' => time()
        ];
    }

    public function closeSession(array $params): array
    {
        $sessionId = $params['session_id'] ?? '';
        
        if (empty($sessionId)) {
            return ['error' => '会话ID不能为空'];
        }
        
        $success = $this->sessionManager->closeSession($sessionId);
        return ['success' => $success];
    }
}

// 主函数 - 演示程序
function main(): void
{
    echo "🚀 SSH HTTP 轮询概念验证\n";
    echo "==========================\n\n";
    
    $storage = new SessionStorage();
    $sessionManager = new SshSessionManager($storage);
    $apiController = new SshApiController($sessionManager);
    
    // 演示1：创建会话（使用提供的SSH服务器）
    echo "📋 步骤 1: 创建 SSH 会话\n";
    $response = $apiController->createSession([
        'host' => '10.211.55.47',
        'user' => 'parallels',
        'password' => '1234qwqw', // 使用提供的密码
    ]);

    if (isset($response['error'])) {
        echo "❌ 创建会话失败: {$response['error']}\n";
        return;
    }
    
    $sessionId = $response['session_id'];
    echo "\n";
    
    // 演示2：发送命令
    echo "📋 步骤 2: 发送命令\n";
    $commands = ['ls -la', 'pwd', 'whoami'];

    foreach ($commands as $command) {
        $apiController->sendCommand([
            'session_id' => $sessionId,
            'command' => $command
        ]);
        
        // 等待一下让命令执行
        sleep(1);
        
        // 轮询输出
        $output = $apiController->pollOutput(['session_id' => $sessionId]);
        if ($output['new_output']) {
            echo "   输出: " . trim($output['new_output']) . "\n";
        }
    }
    
    echo "\n";
    
    // 演示3：模拟前端轮询
    echo "📋 步骤 3: 模拟前端轮询 (5秒)\n";
    $startTime = time();
    $pollCount = 0;
    
    while ((time() - $startTime) < 5) {
        $output = $apiController->pollOutput(['session_id' => $sessionId]);
        $pollCount++;
        
        if ($output['new_output']) {
            echo "   [轮询 #{$pollCount}] 新输出: " . trim($output['new_output']) . "\n";
        } else {
            echo "   [轮询 #{$pollCount}] 无新输出\n";
        }
        
        usleep(500000); // 500ms 间隔
    }
    
    echo "\n";
    
    // 演示4：清理会话
    echo "📋 步骤 4: 清理会话\n";
    $sessionManager->cleanupExpiredSessions(0); // 立即清理
    
    echo "\n✅ 演示完成！\n";
    echo "\n💡 这个概念验证展示了：\n";
    echo "   - SSH 进程创建和管理\n";
    echo "   - 命令发送和输出读取\n";
    echo "   - 会话状态存储\n";
    echo "   - HTTP API 接口设计\n";
    echo "   - 轮询机制模拟\n";
    echo "   - 会话清理机制\n";
}

// 运行演示
if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "请在命令行环境中运行此脚本\n";
}
