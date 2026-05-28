<?php

namespace App\Controller;

use App\Momentum\MomentumComputer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class MomentumController extends AbstractController
{
    #[Route('/momentum/refresh', name: 'app_momentum_refresh', methods: ['POST'])]
    public function refresh(Request $request, MomentumComputer $momentumComputer): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('refresh_momentum', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $rows = $momentumComputer->computeAll((new \DateTimeImmutable('today'))->setTime(0, 0));
        $computed = count(array_filter($rows, static fn (array $row): bool => $row['status'] === 'computed'));

        $this->addFlash('success', sprintf('%d score%s momentum recalcule%s.', $computed, $computed > 1 ? 's' : '', $computed > 1 ? 's' : ''));

        return $this->redirectToRoute('app_home');
    }
}
