#!/usr/bin/env php
<?php

/**
 * 简化的进程管理演示
 * 
 * 使用本地 shell 命令来演示 HTTP 轮询的核心概念
 * 避免 SSH 连接的复杂性
 */

declare(strict_types=1);

class SimpleProcessDemo
{
    private array $sessions = [];
    private array $processes = [];

    public function createSession(): string
    {
        $sessionId = bin2hex(random_bytes(8));
        
        // 创建一个交互式 shell 进程
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];
        
        // 使用 bash 而不是 SSH
        $process = proc_open('/bin/bash', $descriptors, $pipes);
        
        if (is_resource($process)) {
            // 设置非阻塞模式
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            $this->processes[$sessionId] = [
                'resource' => $process,
                'pipes' => $pipes
            ];
            
            $this->sessions[$sessionId] = [
                'created_at' => time(),
                'last_activity' => time(),
                'output_buffer' => "本地 Shell 会话已创建\n",
                'status' => 'active'
            ];
            
            echo "✅ 会话创建成功: {$sessionId}\n";
            return $sessionId;
        }
        
        throw new RuntimeException('无法创建进程');
    }

    public function sendCommand(string $sessionId, string $command): bool
    {
        if (!isset($this->processes[$sessionId])) {
            return false;
        }
        
        $pipes = $this->processes[$sessionId]['pipes'];
        
        // 写入命令
        $input = $command . "\n";
        $written = fwrite($pipes[0], $input);
        
        if ($written === false) {
            echo "❌ 命令发送失败\n";
            return false;
        }
        
        echo "📤 发送命令: {$command}\n";
        
        // 更新会话活动时间
        $this->sessions[$sessionId]['last_activity'] = time();
        
        return true;
    }

    public function readOutput(string $sessionId): string
    {
        if (!isset($this->processes[$sessionId])) {
            return '';
        }
        
        $pipes = $this->processes[$sessionId]['pipes'];
        $output = '';
        
        // 非阻塞读取 stdout
        while (($line = fgets($pipes[1])) !== false) {
            $output .= $line;
        }
        
        // 非阻塞读取 stderr
        while (($line = fgets($pipes[2])) !== false) {
            $output .= '[STDERR] ' . $line;
        }
        
        if ($output) {
            // 更新输出缓冲区
            $this->sessions[$sessionId]['output_buffer'] .= $output;
            // 限制缓冲区大小
            if (strlen($this->sessions[$sessionId]['output_buffer']) > 8192) {
                $this->sessions[$sessionId]['output_buffer'] = 
                    substr($this->sessions[$sessionId]['output_buffer'], -8192);
            }
            
            echo "📥 收到输出 (" . strlen($output) . " 字节): " . trim($output) . "\n";
        }
        
        return $output;
    }

    public function getFullOutput(string $sessionId): string
    {
        return $this->sessions[$sessionId]['output_buffer'] ?? '';
    }

    public function closeSession(string $sessionId): bool
    {
        if (isset($this->processes[$sessionId])) {
            $process = $this->processes[$sessionId];
            
            // 关闭管道
            foreach ($process['pipes'] as $pipe) {
                fclose($pipe);
            }
            
            // 终止进程
            proc_terminate($process['resource']);
            proc_close($process['resource']);
            
            unset($this->processes[$sessionId]);
        }
        
        unset($this->sessions[$sessionId]);
        echo "🔒 会话已关闭: {$sessionId}\n";
        return true;
    }

    public function simulatePollingDemo(): void
    {
        echo "🚀 简化进程管理演示\n";
        echo "===================\n\n";
        
        // 1. 创建会话
        $sessionId = $this->createSession();
        
        // 2. 发送一些命令
        $commands = [
            'echo "Hello World"',
            'pwd',
            'ls -la | head -5',
            'whoami',
            'date'
        ];
        
        foreach ($commands as $command) {
            echo "\n--- 执行命令: {$command} ---\n";
            $this->sendCommand($sessionId, $command);
            
            // 等待命令执行
            usleep(200000); // 200ms
            
            // 读取输出
            $this->readOutput($sessionId);
            
            // 短暂暂停
            usleep(300000); // 300ms
        }
        
        // 3. 模拟轮询
        echo "\n--- 模拟 HTTP 轮询 (3秒) ---\n";
        $startTime = time();
        $pollCount = 0;
        
        while ((time() - $startTime) < 3) {
            $pollCount++;
            $output = $this->readOutput($sessionId);
            
            if ($output) {
                echo "   [轮询 #{$pollCount}] 获得新输出\n";
            } else {
                echo "   [轮询 #{$pollCount}] 无输出\n";
            }
            
            usleep(500000); // 500ms
        }
        
        // 4. 显示完整输出
        echo "\n--- 完整输出缓冲 ---\n";
        echo $this->getFullOutput($sessionId);
        
        // 5. 清理
        echo "\n--- 清理会话 ---\n";
        $this->closeSession($sessionId);
        
        echo "\n✅ 演示完成！\n";
    }
}

// API 模拟类
class MockHttpApi
{
    private SimpleProcessDemo $demo;
    
    public function __construct()
    {
        $this->demo = new SimpleProcessDemo();
    }
    
    public function createSession(array $params): array
    {
        try {
            $sessionId = $this->demo->createSession();
            return [
                'success' => true,
                'session_id' => $sessionId,
                'message' => '会话创建成功'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    public function sendCommand(array $params): array
    {
        $sessionId = $params['session_id'] ?? '';
        $command = $params['command'] ?? '';
        
        if (empty($sessionId) || empty($command)) {
            return [
                'success' => false,
                'error' => '参数不完整'
            ];
        }
        
        $success = $this->demo->sendCommand($sessionId, $command);
        return ['success' => $success];
    }
    
    public function pollOutput(array $params): array
    {
        $sessionId = $params['session_id'] ?? '';
        
        if (empty($sessionId)) {
            return [
                'success' => false,
                'error' => '会话ID不能为空'
            ];
        }
        
        $newOutput = $this->demo->readOutput($sessionId);
        $fullOutput = $this->demo->getFullOutput($sessionId);
        
        return [
            'success' => true,
            'new_output' => $newOutput,
            'full_output' => $fullOutput,
            'timestamp' => time(),
            'has_new_data' => !empty($newOutput)
        ];
    }
    
    public function closeSession(array $params): array
    {
        $sessionId = $params['session_id'] ?? '';
        
        if (empty($sessionId)) {
            return [
                'success' => false,
                'error' => '会话ID不能为空'
            ];
        }
        
        $success = $this->demo->closeSession($sessionId);
        return ['success' => $success];
    }
    
    public function demonstrateApi(): void
    {
        echo "🌐 HTTP API 演示\n";
        echo "===============\n\n";
        
        // 1. 创建会话
        echo "1. 创建会话\n";
        $response = $this->createSession([]);
        echo "   API 响应: " . json_encode($response, JSON_UNESCAPED_UNICODE) . "\n\n";
        
        if (!$response['success']) {
            return;
        }
        
        $sessionId = $response['session_id'];
        
        // 2. 发送命令
        echo "2. 发送命令\n";
        $commands = ['echo "API Test"', 'pwd', 'date'];
        
        foreach ($commands as $command) {
            $response = $this->sendCommand([
                'session_id' => $sessionId,
                'command' => $command
            ]);
            echo "   发送 '{$command}': " . json_encode($response) . "\n";
            
            // 等待执行
            usleep(200000);
            
            // 轮询输出
            $response = $this->pollOutput(['session_id' => $sessionId]);
            echo "   轮询结果: " . json_encode([
                'has_output' => $response['has_new_data'],
                'output_length' => strlen($response['new_output'])
            ]) . "\n";
            
            usleep(300000);
        }
        
        // 3. 关闭会话
        echo "\n3. 关闭会话\n";
        $response = $this->closeSession(['session_id' => $sessionId]);
        echo "   API 响应: " . json_encode($response) . "\n";
        
        echo "\n✅ API 演示完成！\n";
    }
}

// 主程序
function main(): void
{
    global $argv;
    if (count($argv ?? []) > 1 && $argv[1] === 'api') {
        // API 演示模式
        $api = new MockHttpApi();
        $api->demonstrateApi();
    } else {
        // 基础演示模式
        $demo = new SimpleProcessDemo();
        $demo->simulatePollingDemo();
    }
}

if (php_sapi_name() === 'cli') {
    main();
} else {
    echo "请在命令行环境中运行此脚本\n";
}
