<?php

declare(strict_types=1);

namespace App\Controller;

use App\ImageOptimizer;
use App\Repository\JobRepository;
use App\Repository\WorksheetRepository;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\FileUploader;
use App\Service\ModalForms;
use App\Service\SignUpValidator;
use App\Service\UserCreator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Notifier\Notification\Notification;
use Symfony\Component\Notifier\NotifierInterface;
use App\Form\User\EditProfileFormType;

class UserController extends AbstractController
{
    /**
     * Time in seconds 3600 - one hour
     */
    public const CACHE_MAX_AGE = '3600';

    public const ROLE_EMPLOYEE = 'ROLE_EMPLOYEE';

    public const ROLE_CUSTOMER = 'ROLE_CUSTOMER';

    public const ROLE_BUYER = 'ROLE_BUYER';

    public const ROLE_SUPER_ADMIN = 'ROLE_SUPER_ADMIN';

    private $security;

    /**
     * @param Security $security
     * @param Environment $twig
     * @param ManagerRegistry $doctrine
     * @param ModalForms $modalForms
     * @param ImageOptimizer $imageOptimizer
     * @param string $targetDirectory
     */
    public function __construct(
        Security $security,
        Environment $twig,
        ManagerRegistry $doctrine,
        ModalForms $modalForms,
        ImageOptimizer $imageOptimizer,
        string $targetDirectory
    ) {
        $this->security = $security;
        $this->twig = $twig;
        $this->doctrine = $doctrine;
        $this->modalForms = $modalForms;
        $this->imageOptimizer = $imageOptimizer;
        $this->targetDirectory = $targetDirectory;
    }

    public function signUp(Request $request): Response
    {
        return $this->render('profile/sign-up.html.twig');
    }

    /**
     * Method to redirect logged in user
     *
     * @Route("/lk", name="app_lk")
     */
    public function login(Request $request): Response
    {
        $user = $this->getUser();
        if ($user != null && in_array(self::ROLE_CUSTOMER, $user->getRoles())) {
            return $this->redirectToRoute("app_lk_customer");
        } elseif ($user != null && in_array(self::ROLE_EMPLOYEE, $user->getRoles())) {
            return $this->redirectToRoute("app_lk_employee");
        } elseif ($user != null && in_array(self::ROLE_BUYER, $user->getRoles())) {
            return $this->redirectToRoute("app_lk_buyer");
        } elseif ($user != null && in_array(self::ROLE_SUPER_ADMIN, $user->getRoles())) {
            return $this->redirectToRoute("admin");
        } else {
            return $this->redirectToRoute("app_main");
        }
    }

    /**
     * Require ROLE_CUSTOMER for *every* controller method in this class.
     *
     * @IsGranted("ROLE_CUSTOMER")
     * @Route("/lk/customer", name="app_lk_customer")
     */
    public function customerProfile(
        Request $request,
        TranslatorInterface $translator,
        NotifierInterface $notifier,
        WorksheetRepository $worksheetRepository
    ): Response {
        if ($this->isGranted(self::ROLE_CUSTOMER)) {
            $user = $this->security->getUser();
            $worksheets = $worksheetRepository->findByUser($user->getId());

            // Resize avatar if exist
            if ($user->getAvatar()) {
                $this->imageOptimizer->resize($this->targetDirectory.'/'.$user->getAvatar());
            }

            {
                return $this->render('user/lk_customer.html.twig', [
                    'user' => $user,
                    'worksheets' => $worksheets,
                    'ticketForm' => $this->modalForms->ticketForm($request)->createView()
                ]);
            }
        } else {
            $message = $translator->trans('Please login', array(), 'flash');
            $notifier->send(new Notification($message, ['browser']));
            return $this->redirectToRoute("app_main");
        }
    }

    /**
     * Require ROLE_EMPLOYEE for *every* controller method in this class.
     *
     * @IsGranted("ROLE_EMPLOYEE")
     * @Route("/lk/employer", name="app_lk_employee")
     */
    public function employeeProfile(
        Request $request,
        TranslatorInterface $translator,
        NotifierInterface $notifier,
        JobRepository $jobRepository
    ): Response {
        if ($this->isGranted(self::ROLE_EMPLOYEE)) {
            $user = $this->security->getUser();
            $jobs = $jobRepository->findByUser($user->getId());

            // Resize avatar if exist
            if ($user->getAvatar()) {
                $this->imageOptimizer->resize($this->targetDirectory.'/'.$user->getAvatar());
            }

            {
                return $this->render('user/lk_customer.html.twig', [
                    'user' => $user,
                    'jobs' => $jobs,
                    'ticketForm' => $this->modalForms->ticketForm($request)->createView()
                ]);
            }
        } else {
            $message = $translator->trans('Please login', array(), 'flash');
            $notifier->send(new Notification($message, ['browser']));
            return $this->redirectToRoute("app_main");
        }
    }

    /**
     * Require ROLE_BUYER for *every* controller method in this class.
     *
     * @IsGranted("ROLE_BUYER")
     * @Route("/lk/buyer", name="app_lk_buyer")
     */
    public function buyerProfile(
        Request $request,
        TranslatorInterface $translator,
        NotifierInterface $notifier
    ): Response {
        if ($this->isGranted(self::ROLE_BUYER)) {
            $user = $this->security->getUser();

            // Resize avatar if exist
            if ($user->getAvatar()) {
                $this->imageOptimizer->resize($this->targetDirectory.'/'.$user->getAvatar());
            }

            {
                return $this->render('user/lk_customer.html.twig', [
                    'user' => $user,
                    'ticketForm' => $this->modalForms->ticketForm($request)->createView()
                ]);
            }
        } else {
            $message = $translator->trans('Please login', array(), 'flash');
            $notifier->send(new Notification($message, ['browser']));
            return $this->redirectToRoute("app_main");
        }
    }

    /**
     *
     * @Route("/lk/edit-profile", name="app_edit_profile")
     */
    public function editProfile(
        Request $request,
        TranslatorInterface $translator,
        NotifierInterface $notifier,
        UserPasswordHasherInterface $passwordHasher,
        FileUploader $fileUploader
    ): Response {
        if (
            $this->isGranted(self::ROLE_BUYER) ||
            $this->isGranted(self::ROLE_CUSTOMER) ||
            $this->isGranted(self::ROLE_EMPLOYEE)
        ) {
            $user = $this->security->getUser();
            $form = $this->createForm(EditProfileFormType::class, $user);
            $form->handleRequest($request);
            $entityManager = $this->doctrine->getManager();

            if ($form->isSubmitted()) {
                $post = $request->request->get('edit_profile_form');
                // Set new password if changed
                if ($post['plainPassword']['first'] !=='' && $post['plainPassword']['second'] !=='') {
                    if (strcmp($post['plainPassword']['first'], $post['plainPassword']['second']) == 0) {
                        // encode the plain password
                        $user->setPassword(
                            $passwordHasher->hashPassword(
                                $user,
                                $post['plainPassword']['first']
                            )
                        );
                    } else {
                        $message = $translator->trans('Mismatch password', array(), 'flash');
                        $notifier->send(new Notification($message, ['browser']));
                        return $this->redirectToRoute("app_edit_client_profile");
                    }
                }

                // File upload
                $avatarFile = $form->get('avatar')->getData();
                if ($avatarFile) {
                    $avatarFileName = $fileUploader->upload($avatarFile);
                    $user->setAvatar($avatarFileName);
                }

                $entityManager->persist($user);
                $entityManager->flush();

                $message = $translator->trans('Profile updated', array(), 'flash');
                $notifier->send(new Notification($message, ['browser']));
                $referer = $request->headers->get('referer');
                return new RedirectResponse($referer);
            }

            return new Response($this->twig->render('user/edit_profile.html.twig', [
                'user' => $user,
                'form' => $form->createView(),
                'ticketForm' => $this->modalForms->ticketForm($request)->createView()
            ]));
        } else {
            $message = $translator->trans('Please login', array(), 'flash');
            $notifier->send(new Notification($message, ['browser']));
            return $this->redirectToRoute("app_main");
        }
    }
}
