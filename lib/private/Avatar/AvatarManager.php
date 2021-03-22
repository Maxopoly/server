<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author John Molakvoæ (skjnldsv) <skjnldsv@protonmail.com>
 * @author Julius Härtl <jus@bitgrid.net>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Michael Weimann <mail@michael-weimann.eu>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

namespace OC\Avatar;

use OC\User\Manager;
use OC\User\NoUserException;
use OCP\Accounts\IAccountManager;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IAvatar;
use OCP\IAvatarManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IUserSession;

/**
 * This class implements methods to access Avatar functionality
 */
class AvatarManager implements IAvatarManager {

	/** @var IUserSession */
	private $userSession;

	/** @var Manager */
	private $userManager;

	/** @var IAppData */
	private $appData;

	/** @var IL10N */
	private $l;

	/** @var ILogger  */
	private $logger;

	/** @var IConfig */
	private $config;

	/** @var IAccountManager */
	private $accountManager;

	/**
	 * AvatarManager constructor.
	 *
	 * @param Manager $userManager
	 * @param IAppData $appData
	 * @param IL10N $l
	 * @param ILogger $logger
	 * @param IConfig $config
	 * @param IUserSession $userSession
	 */
	public function __construct(
			IUserSession $userSession,
			Manager $userManager,
			IAppData $appData,
			IL10N $l,
			ILogger $logger,
			IConfig $config,
			IAccountManager $accountManager) {
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->appData = $appData;
		$this->l = $l;
		$this->logger = $logger;
		$this->config = $config;
		$this->accountManager = $accountManager;
	}

	/**
	 * return a user specific instance of \OCP\IAvatar
	 * @see \OCP\IAvatar
	 * @param string $userId the ownCloud user id
	 * @return \OCP\IAvatar
	 * @throws \Exception In case the username is potentially dangerous
	 * @throws NotFoundException In case there is no user folder yet
	 */
	public function getAvatar(string $userId) : IAvatar {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new \Exception('user does not exist');
		}

		// sanitize userID - fixes casing issue (needed for the filesystem stuff that is done below)
		$userId = $user->getUID();

		$requestingUser = null;
		if ($this->userSession !== null) {
			$requestingUser = $this->userSession->getUser();
		}

		$canShowRealAvatar = true;

		// requesting in public page or requesting avatar of different user needs privacy check
		if ($requestingUser === null || $requestingUser->getUID() !== $userId) {
			$account = $this->accountManager->getAccount($user);
			$avatarProperties = $account->getProperty(IAccountManager::PROPERTY_AVATAR);
			$avatarVisibility = $avatarProperties->getScope();

			// either the avatar is fully private and should never be shown to non-owners,
			// or its scope is visible for local users only
			if ($avatarVisibility === IAccountManager::VISIBILITY_PRIVATE || ($requestingUser === null && $avatarVisibility !== IAccountManager::VISIBILITY_PUBLIC)) {
				$canShowRealAvatar = false;
			}
		}

		if ($canShowRealAvatar) {
			try {
				$folder = $this->appData->getFolder($userId);
			} catch (NotFoundException $e) {
				$folder = $this->appData->newFolder($userId);
			}

			return new UserAvatar($folder, $this->l, $user, $this->logger, $this->config);
		} else {
			// FIXME: guest avatar is re-generated every time, use a cache instead
			// see how UserAvatar caches the generated one
			return $this->getGuestAvatar($userId);
		}
	}

	/**
	 * Clear generated avatars
	 */
	public function clearCachedAvatars() {
		$users = $this->config->getUsersForUserValue('avatar', 'generated', 'true');
		foreach ($users as $userId) {
			try {
				$folder = $this->appData->getFolder($userId);
				$folder->delete();
			} catch (NotFoundException $e) {
				$this->logger->debug("No cache for the user $userId. Ignoring...");
			}
			$this->config->setUserValue($userId, 'avatar', 'generated', 'false');
		}
	}

	public function deleteUserAvatar(string $userId): void {
		try {
			$folder = $this->appData->getFolder($userId);
			$folder->delete();
		} catch (NotFoundException $e) {
			$this->logger->debug("No cache for the user $userId. Ignoring avatar deletion");
		} catch (NotPermittedException $e) {
			$this->logger->error("Unable to delete user avatars for $userId. gnoring avatar deletion");
		} catch (NoUserException $e) {
			$this->logger->debug("User $userId not found. gnoring avatar deletion");
		}
		$this->config->deleteUserValue($userId, 'avatar', 'generated');
	}

	/**
	 * Returns a GuestAvatar.
	 *
	 * @param string $name The guest name, e.g. "Albert".
	 * @return IAvatar
	 */
	public function getGuestAvatar(string $name): IAvatar {
		return new GuestAvatar($name, $this->logger);
	}
}
