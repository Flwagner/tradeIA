<?php

namespace App\Controller;

use App\Backtest\TacticalBacktester;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BacktestController extends AbstractController
{
    #[Route('/backtest', name: 'app_backtest', methods: ['GET'])]
    public function __invoke(Request $request, TacticalBacktester $backtester): Response
    {
        $period = (string) $request->query->get('period', '6m');
        $stopLoss = (float) $request->query->get('stop_loss', 10);

        return $this->render('backtest/index.html.twig', [
            'result' => $backtester->run($period, $stopLoss),
        ]);
    }
}
