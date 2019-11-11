<?php declare(strict_types=1);


/**
 * FilesLock - Temporary Files Lock
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2018, Maxence Lange <maxence@artificial-owl.com>
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */


namespace OCA\FilesLock\Command;


use OC\Core\Command\Base;
use OC\User\NoUserException;
use OCA\FilesLock\Exceptions\AlreadyLockedException;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\SuccessException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCA\FilesLock\Service\MiscService;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;


class Lock extends Base {


	/** @var IUserManager */
	private $userManager;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var MiscService */
	private $miscService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IUserManager $userManager
	 * @param FileService $fileService
	 * @param LockService $lockService
	 * @param MiscService $miscService
	 */
	public function __construct(
		IUserManager $userManager, FileService $fileService, LockService $lockService,
		MiscService $miscService
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('files:lock')
			 ->addOption('unlock', 'u', InputOption::VALUE_NONE, 'unlock a file')
			 ->addOption('status', 's', InputOption::VALUE_NONE, 'returns lock status of the file')
			 ->addArgument('file_id', InputArgument::REQUIRED, 'Id of the locked file')
			 ->addArgument('user_id', InputArgument::OPTIONAL, 'owner of the lock', '')
			 ->setDescription('lock a file to a user');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws NoUserException
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 * @throws AlreadyLockedException
	 * @throws NotFileException
	 * @throws InvalidPathException
	 */
	protected function execute(InputInterface $input, OutputInterface $output) {
		$fileId = (int)$input->getArgument('file_id');
		$userId = $input->getArgument('user_id');


		try {
			$this->getStatus($input, $output, $fileId);
			$this->getUnlock($input, $output, $fileId);
		} catch (SuccessException $e) {
			return;
		}

		if ($userId === '') {
			throw new NoUserException('please specify userId');
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new NoUserException("Unknown user '" . $userId . "'");
		}

		$file = $this->fileService->getFileFromId($user->getUID(), $fileId);

		$output->writeln('<info>locking ' . $file->getName() . ' to ' . $userId . '</info>');
		$this->lockService->lockFile($file, $user);
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param int $fileId
	 *
	 * @throws SuccessException
	 */
	private function getStatus(InputInterface $input, OutputInterface $output, int $fileId) {
		if (!$input->getOption('status')) {
			return;
		}

		try {
			$lock = $this->lockService->getLockFromFileId($fileId);
			$output->writeln(
				'File #' . $fileId . ' is <comment>locked</comment> by ' . $lock->getUserId()
			);
		} catch (LockNotFoundException $e) {
			$output->writeln('File #' . $fileId . ' is <info>not locked<info>');
		}

		throw new SuccessException();
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param int $fileId
	 *
	 * @throws SuccessException
	 * @throws UnauthorizedUnlockException
	 */
	private function getUnlock(InputInterface $input, OutputInterface $output, int $fileId) {
		if (!$input->getOption('unlock')) {
			return;
		}

		$output->writeln('<info>unlocking File #' . $fileId);
		try {
			$this->lockService->unlockFile($fileId, '', true);
		} catch (LockNotFoundException $e) {
		}

		throw new SuccessException();
	}

}

