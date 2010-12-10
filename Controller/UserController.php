<?php

/**
 * (c) Thibault Duplessis <thibault.duplessis@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Bundle\DoctrineUserBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller as Controller;
use Bundle\DoctrineUserBundle\Model\User;
use Bundle\DoctrineUserBundle\Form\ChangePassword;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ForbiddenHttpException;
use Symfony\Component\Security\Authentication\Token\UsernamePasswordToken;

/**
 * RESTful controller managing user CRUD
 */
class UserController extends Controller
{
    /**
     * Show all users
     **/
    public function listAction()
    {
        $users = $this->get('doctrine_user.repository.user')->findAll();

        return $this->render('DoctrineUserBundle:User:list.'.$this->getRenderer(), array('users' => $users));
    }

    /**
     * Show one user
     */
    public function showAction($username)
    {
        $user = $this->findUserByUsername($username);

        return $this->render('DoctrineUserBundle:User:show.'.$this->getRenderer(), array('user' => $user));
    }

    /**
     * Edit one user, show the edit form
     */
    public function editAction($username)
    {
        $user = $this->findUserByUsername($username);
        $form = $this->createForm($user);

        return $this->render('DoctrineUserBundle:User:edit.'.$this->getRenderer(), array(
            'form'      => $form,
            'username'  => $username
        ));
    }

    /**
     * Update a user
     */
    public function updateAction($username)
    {
        $user = $this->findUserByUsername($username);
        $form = $this->createForm($user);

        if ($data = $this->get('request')->request->get($form->getName())) {
            $form->bind($data);
            if ($form->isValid()) {
                $this->saveUser($user);
                $this->get('session')->setFlash('doctrine_user_user_update', 'success');
                $userUrl = $this->generateUrl('doctrine_user_user_show', array('username' => $user->getUsername()));
                return $this->redirect($userUrl);
            }
        }

        return $this->render('DoctrineUserBundle:User:edit.'.$this->getRenderer(), array(
            'form'      => $form,
            'username'  => $username
        ));
    }

    /**
     * Show the new form
     */
    public function newAction()
    {
        $form = $this->createForm();

        return $this->render('DoctrineUserBundle:User:new.'.$this->getRenderer(), array(
            'form' => $form
        ));
    }

    /**
     * Create a user and send a confirmation email
     */
    public function createAction()
    {
        $form = $this->createForm();
        $form->setValidationGroups('Registration');
        $form->bind($this->get('request')->request->get($form->getName()));

        if ($form->isValid()) {
            $user = $form->getData();
            if ($this->container->getParameter('doctrine_user.confirmation_email.enabled')) {
                $user->setEnabled(false);
                $this->saveUser($user);
                $this->get('session')->set('doctrine_user_send_confirmation_email/email', $user->getEmail());
                $url = $this->generateUrl('doctrine_user_user_send_confirmation_email');
            } else {
                $user->setEnabled(true);
                $this->saveUser($user);
                $this->authenticateUser($user);
                $url = $this->generateUrl('doctrine_user_user_confirmed');
            }

            $this->get('session')->setFlash('doctrine_user_user_create', 'success');
            return $this->redirect($url);
        }

        return $this->render('DoctrineUserBundle:User:new.'.$this->getRenderer(), array(
            'form' => $form
        ));
    }

    /**
     * Send the confirmation email containing a link to the confirmation page,
     * then redirect the check email page
     */
    public function sendConfirmationEmailAction()
    {
        if (!$this->container->getParameter('doctrine_user.confirmation_email.enabled')) {
            throw new NotFoundHttpException('Email confirmation is disabled');
        }

        $email = $this->get('session')->get('doctrine_user_send_confirmation_email/email');
        $user = $this->findUser('email', $email);

        $mailer = $this->get('mailer');
        $message = $this->getConfirmationEmailMessage($user);
        $mailer->send($message);

        return $this->redirect($this->generateUrl('doctrine_user_user_check_confirmation_email'));
    }

    protected function getConfirmationEmailMessage(User $user)
    {
        $template = $this->container->getParameter('doctrine_user.confirmation_email.template');
        // Render the email, use the first line as the subject, and the rest as the body
        $rendered = $this->renderView($template.'.'.$this->getRenderer(), array(
            'user' => $user,
            'confirmationUrl' => $this->generateUrl('doctrine_user_user_confirm', array('token' => $user->getConfirmationToken()), true)
        ));
        $renderedLines = explode("\n", trim($rendered));
        $subject = $renderedLines[0];
        $body = implode("\n", array_slice($renderedLines, 1));

        $fromEmail = $this->container->getParameter('doctrine_user.confirmation_email.from_email');
        return \Swift_Message::newInstance()
            ->setSubject($subject)
            ->setFrom($fromEmail)
            ->setTo($user->getEmail())
            ->setBody($body);
    }

    /**
     * Tell the user to check his email provider
     */
    public function checkConfirmationEmailAction()
    {
        $email = $this->get('session')->get('doctrine_user_send_confirmation_email/email');
        $user = $this->findUser('email', $email);

        return $this->render('DoctrineUserBundle:User:checkConfirmationEmail.'.$this->getRenderer(), array(
            'user' => $user,
        ));
    }

    /**
     * Receive the confirmation token from user email provider, login the user
     */
    public function confirmAction($token)
    {
        $user = $this->findUser('confirmationToken', $token);
        $user->setConfirmationToken(null);
        $user->setEnabled(true);

        $this->saveUser($user);
        $this->authenticateUser($user);

        return $this->redirect($this->generateUrl('doctrine_user_user_confirmed'));
    }

    /**
     * Tell the user his account is now confirmed
     */
    public function confirmedAction()
    {
        $user = $this->get('security.context')->getUser();
        if (!$user) {
            throw new ForbiddenHttpException(sprintf('No user confirmed'));
        }

        return $this->render('DoctrineUserBundle:User:confirmed.'.$this->getRenderer(), array(
            'user' => $user,
        ));
    }

    /**
     * Delete one user
     */
    public function deleteAction($username)
    {
        $user = $this->findUserByUsername($username);

        $objectManager = $this->get('doctrine_user.repository.user')->getObjectManager();
        $objectManager->remove($user);
        $objectManager->flush();
        $this->get('session')->setFlash('doctrine_user_user_delete', 'success');

        return $this->redirect($this->generateUrl('doctrine_user_user_list'));
    }

    /**
     * Change user password: show form
     */
    public function changePasswordAction()
    {
        $user = $this->get('security.context')->getUser();
        if (!$user) {
            throw new ForbiddenHttpException(sprintf('Must be logged in to change your password'));
        }

        $form = $this->createChangePasswordForm($user);

        return $this->render('DoctrineUserBundle:User:changePassword.'.$this->getRenderer(), array(
            'form' => $form
        ));
    }

    /**
     * Change user password: submit form
     */
    public function changePasswordUpdateAction()
    {
        $user = $this->get('security.context')->getUser();
        if (!$user) {
            throw new ForbiddenHttpException(sprintf('Must be logged in to change your password'));
        }

        $form = $this->createChangePasswordForm($user);
        $form->bind($this->get('request')->request->get($form->getName()));
        if ($form->isValid()) {
            $encoder = $this->get('doctrine_user.password_encoder');
            $user->setPassword($encoder->encodePassword($form->getNewPassword(), $user->getSalt()));

            $this->get('doctrine_user.repository.user')->getObjectManager()->flush();
            $userUrl = $this->generateUrl('doctrine_user_user_show', array('username' => $user->getUsername()));
            return $this->redirect($userUrl);
        }

        return $this->render('DoctrineUserBundle:User:changePassword.'.$this->getRenderer(), array(
            'form' => $form
        ));
    }

    /**
     * Find a username by its lowercased username
     *
     * @param string $username username
     * @throw NotFoundException if user does not exist
     * @return User
     **/
    public function findUserByUsername($username)
    {
        $user = $this->get('doctrine_user.repository.user')->findOneByUsername($username);

        if (empty($user)) {
            throw new NotFoundHttpException(sprintf('The user with username "%s" does not exist', $username));
        }

        return $user;
    }

    /**
     * Find a user by a specific property
     *
     * @param string $key property name
     * @param mixed $value property value
     * @throw NotFoundException if user does not exist
     * @return User
     */
    protected function findUser($key, $value)
    {
        if (!empty($value)) {
            $user = $this->get('doctrine_user.repository.user')->{'findOneBy'.ucfirst($key)}($value);
        }

        if (empty($user)) {
            throw new NotFoundHttpException(sprintf('The user with "%s" does not exist for value "%s"', $key, $value));
        }

        return $user;
    }

    /**
     * Save a user in database
     *
     * @param User $user
     * @return null
     **/
    public function saveUser(User $user)
    {
        if (0 !== strlen($password = $user->getPlainPassword())) {
            $encoder = $this->get('doctrine_user.encoder');
            $user->setPassword($encoder->encodePassword($password, $user->getSalt()));
            $user->eraseCredentials();
        }
        
        $objectManager = $this->get('doctrine_user.repository.user')->getObjectManager();
        $objectManager->persist($user);
        $objectManager->flush();
    }

    /**
     * Authenticate a user with Symfony Security
     *
     * @return null
     **/
    public function authenticateUser(User $user)
    {
        $token = new UsernamePasswordToken($user, null, get_class($this->get('doctrine_user.repository.user')), $user->getRoles());
        $this->get('security.context')->setToken($token);
    }

    /**
     * Create a UserForm instance and returns it
     *
     * @param User $object
     * @return Bundle\DoctrineUserBundle\Form\UserForm
     */
    protected function createForm($object = null)
    {
        $form = $this->get('doctrine_user.form.user');
        if (null === $object) {
            $object = $this->get('doctrine_user.repository.user')->createUserInstance();
        }

        $form->setData($object);

        return $form;
    }

    protected function createChangePasswordForm(User $user)
    {
        $form = $this->get('doctrine_user.form.change_password');
        $form->setData(new ChangePassword($user));

        return $form;
    }

    protected function getRenderer()
    {
        return $this->container->getParameter('doctrine_user.template.renderer');
    }
}
