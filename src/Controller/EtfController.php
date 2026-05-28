<?php

namespace App\Controller;

use App\Repository\EtfRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class EtfController extends AbstractController
{
    #[Route('/etfs/{id}/delete', name: 'app_etf_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        EtfRepository $etfRepository,
        EntityManagerInterface $entityManager,
    ): RedirectResponse {
        $etf = $etfRepository->find($id);

        if ($etf === null) {
            throw $this->createNotFoundException('ETF introuvable.');
        }

        if (!$this->isCsrfTokenValid('delete_etf_' . $etf->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF invalide.');
        }

        $entityManager->remove($etf);
        $entityManager->flush();

        $this->addFlash('success', sprintf('%s et ses donnees ont ete supprimes.', $etf->getSymbol()));

        return $this->redirectToRoute('app_home');
    }
}
