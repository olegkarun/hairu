<?php
namespace PAGEmachine\Hairu\Controller;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2014 Mathias Brodala <mbrodala@pagemachine.de>, PAGEmachine AG
 *  
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;
use TYPO3\CMS\Extbase\Mvc\View\ViewInterface;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;
use PAGEmachine\Hairu\LoginType;
use PAGEmachine\Hairu\Mvc\Controller\ActionController;

class LoginController extends ActionController {

  /**
   * @var \PAGEmachine\Hairu\Authentication\AuthenticationService
   * @inject
   */
  protected $authenticationService;

  /**
   * @var \TYPO3\CMS\Extbase\Security\Cryptography\HashService
   * @inject
   */
  protected $hashService;

  /**
   * @var \TYPO3\CMS\Core\Cache\Frontend\FrontendInterface
   */
  protected $tokenCache;

  /**
   * @param \TYPO3\CMS\Core\Cache\CacheManager $cacheManager
   * @return void
   */
  public function injectCacheManager(\TYPO3\CMS\Core\Cache\CacheManager $cacheManager) {

    $this->tokenCache = $cacheManager->getCache('hairu_token');
  }

  /**
   * Initialize all actions
   *
   * @return void
   */
  protected function initializeAction() {

    // Make global form data (as expected by the CMS core) available
    $formData = GeneralUtility::_GET();
    ArrayUtility::mergeRecursiveWithOverrule($formData, GeneralUtility::_POST());
    $this->request->setArgument('formData', $formData);
  }

  /**
   * Initialize all views
   *
   * @param ViewInterface $view
   * @return void
   */
  protected function initializeView(ViewInterface $view) {

    $view->assign('formData', $this->request->getArgument('formData'));
  }

  /**
   * Login form view
   *
   * @return void
   */
  public function showLoginFormAction() {

    if ($this->authenticationService->isUserAuthenticated()) {

      $this->forward('showLogoutForm');
    }

    $formData = $this->request->getArgument('formData');
    $loginFailed = FALSE;
    $logoutSuccessful = FALSE;

    if (isset($formData['logintype'])) {

      switch ($formData['logintype']) {

        case LoginType::LOGIN:

          $loginFailed = TRUE;
          break;

        case LoginType::LOGOUT:

          $logoutSuccessful = TRUE;
          break;
      }
    }

    list($submitJavaScript, $additionalHiddenFields) = $this->getAdditionalLoginFormCode();

    $this->view->assignMultiple(array(
      'logintype' => LoginType::LOGIN,
      'submitJavaScript' => $submitJavaScript,
      'additionalHiddenFields' => $additionalHiddenFields,
      'loginFailed' => $loginFailed,
      'logoutSuccessful' => $logoutSuccessful,
    ));
  }

  /**
   * Logout form view
   *
   * @return void
   */
  public function showLogoutFormAction() {

    $formData = $this->request->getArgument('formData');
    $loginSuccessful = $this->authenticationService->isUserAuthenticated()
      && isset($formData['logintype'])
      && $formData['logintype'] === LoginType::LOGIN;

    $this->view->assignMultiple(array(
      'logintype' => LoginType::LOGOUT,
      'loginSuccessful' => $loginSuccessful,
      'user' => $this->authenticationService->getAuthenticatedUser(),
    ));
  }

  /**
   * Password reset form view
   *
   * @return void
   */
  public function showPasswordResetFormAction() {}

  /**
   * Start password reset
   *
   * @param string $username
   * @return void
   * 
   * @validate $username NotEmpty
   */
  public function startPasswordResetAction($username) {

    $user = $this->frontendUserRepository->findOneByUsername($username);
    $hash = md5(GeneralUtility::generateRandomBytes(64));
    $token = array(
      'uid' => $user->getUid(),
      'hmac' => $this->hashService->generateHmac($user->getPassword()),
    );

    // Remove other possibly existing tokens
    $this->tokenCache->flushByTag($user->getUid());
    // Store new reset token
    $tokenLifetime = $this->getSettingValue('passwordReset.token.lifetime', 86400); // 1 day
    $this->tokenCache->set($hash, $token, array($user->getUid()), $tokenLifetime);

    $passwordResetPageUid = $this->getSettingValue('passwordReset.page', $this->getFrontendController()->id);
    $hashUri = $this->uriBuilder
      ->setTargetPageUid($passwordResetPageUid)
      ->setUseCacheHash(FALSE)
      ->setCreateAbsoluteUri(TRUE)
      ->uriFor('showPasswordResetForm', array(
        'hash' => $hash,
      ));
    $this->view->assignMultiple(array(
      'user' => $user,
      'hash' => $hash, // Allow for custom URI in Fluid
      'hashUri' => $hashUri,
    ));

    $message = $this->objectManager->get('TYPO3\\CMS\\Core\\Mail\\MailMessage');
    $message
      ->setFrom($this->getSettingValue('passwordReset.mail.from', MailUtility::getSystemFrom()))
      ->setTo($user->getEmail())
      ->setSubject($this->getSettingValue('passwordReset.mail.subject', 'Password reset request'));

    $this->request->setFormat('txt');
    $message->setBody($this->view->render('passwordResetMail'), 'text/plain');
    $this->request->setFormat('html');
    $message->addPart($this->view->render('passwordResetMail'), 'text/html');
    $mailSent = FALSE;

    try {
      
      $mailSent = $message->send();
    } catch (\Swift_SwiftException $e) {
      
      /* Nothing to do ATM */
    }

    if ($mailSent) {

      $this->addLocalizedFlashMessage('resetPassword.started', array($user->getEmail()), FlashMessage::INFO);
    } else {

      $this->addLocalizedFlashMessage('resetPassword.failed.sending', array($user->getEmail()), FlashMessage::ERROR);
    }

    $this->redirect('showPasswordResetForm');
  }

  /**
   * Shorthand helper for getting setting values with optional default values
   *
   * @param string $settingPath Path to the setting, e.g. "foo.bar.qux"
   * @param mixed $defaultValue Default value if no value is set
   * @return mixed
   */
  protected function getSettingValue($settingPath, $defaultValue = NULL) {

    $value = ObjectAccess::getPropertyPath($this->settings, $settingPath);

    // Change type of value to type of default value if possible
    if (!empty($value) && $defaultValue !== NULL) {

      settype($value, gettype($defaultValue));
    }

    $value = !empty($value) ? $value : $defaultValue;

    return $value;
  }

  /**
   * A template method for displaying custom error flash messages, or to
   * display no flash message at all on errors. Override this to customize
   * the flash message in your action controller.
   *
   * @return string The flash message or FALSE if no flash message should be set
   * @api
   */
  protected function getErrorFlashMessage() {
    return FALSE;
  }

  /**
   * Shorthand helper for adding localized flash messages
   *
   * @param string $translationKey
   * @param array $translationArguments
   * @param integer $severity
   */
  protected function addLocalizedFlashMessage($translationKey, array $translationArguments = NULL, $severity) {

    $this->addFlashMessage(
      LocalizationUtility::translate(
        $translationKey,
        $this->request->getControllerExtensionName(),
        $translationArguments
      ),
      '',
      $severity
    );
  }

  /**
   * Gets additional code for login forms based on the
   * TYPO3_CONF_VARS/EXTCONF/felogin/loginFormOnSubmitFuncs hook
   *
   * @return array Array containing code for submit JavaScript
   *                     and additional hidden fields
   */
  protected function getAdditionalLoginFormCode() {

    $submitJavaScript = array();
    $additionalHiddenFields = array();

    if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['felogin']['loginFormOnSubmitFuncs'])) {

      $parameters = array();

      foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['felogin']['loginFormOnSubmitFuncs'] as $callback) {

        $result = GeneralUtility::callUserFunction($callback, $parameters, $this);

        if (isset($result[0])) {

          $submitJavaScript[] = $result[0];
        }

        if (isset($result[1])) {

          $additionalHiddenFields[] = $result[1];
        }
      }
    }

    $submitJavaScript = implode(';', $submitJavaScript);
    $additionalHiddenFields = implode('LF', $additionalHiddenFields);

    return array($submitJavaScript, $additionalHiddenFields);
  }

  /**
   * @return \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController
   */
  protected function getFrontendController() {

    return $GLOBALS['TSFE'];
  }
}
