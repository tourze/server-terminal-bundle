<?php

namespace ServerTerminalBundle\Controller;

use ServerNodeBundle\Repository\NodeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class TerminalController extends AbstractController
{
    #[Route('/server/terminal/{id}', name: 'server-node-terminal')]
    public function index(string $id, Request $request, NodeRepository $nodeRepository): Response
    {
        $node = $nodeRepository->find($id);
        if (!$node) {
            throw new NotFoundHttpException();
        }

        $wsUrl = $_ENV['SERVER_NODE_WS_URL'] ?? '';
        if (!$wsUrl) {
            $schema = $request->isSecure() ? 'wss' : 'ws';
            $wsUrl = "{$schema}://127.0.0.1:{$_ENV['SERVER_NODE_WS_PORT']}";
        }
        $url = trim($wsUrl, '/');
        // 拼接上节点信息
        $url = "{$url}/?nodeId={$node->getId()}";

        return $this->render('@ServerNode/terminal/index.html.twig', [
            'node' => $node,
            'wsUrl' => $url,
        ]);
    }
}
