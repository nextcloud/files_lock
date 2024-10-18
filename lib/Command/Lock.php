<?php

declare(strict_types=1);


/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */


namespace OCA\FilesLock\Command;

use OC\Core\Command\Base;
use OC\User\NoUserException;
use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\SuccessException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\ConfigService;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class Lock extends Base {
	/** @var IUserManager */
	private $userManager;

	/** @var LocksRequest */
	private $locksRequest;

	/** @var FileService */
	private $fileService;

	/** @var LockService */
	private $lockService;

	/** @var ConfigService */
	private $configService;


	/**
	 * CacheUpdate constructor.
	 *
	 * @param IUserManager $userManager
	 * @param FileService $fileService
	 * @param LockService $lockService
	 * @param LocksRequest $locksRequest
	 * @param ConfigService $configService
	 */
	public function __construct(
		IUserManager $userManager, LocksRequest $locksRequest, FileService $fileService,
		LockService $lockService, ConfigService $configService,
	) {
		parent::__construct();

		$this->userManager = $userManager;
		$this->locksRequest = $locksRequest;
		$this->fileService = $fileService;
		$this->lockService = $lockService;
		$this->configService = $configService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('files:lock')
			->addOption('unlock', 'u', InputOption::VALUE_NONE, 'unlock a file')
			->addOption(
				'uninstall', '', InputOption::VALUE_NONE, 'fully uninstall the app from your Nextcloud'
			)
			->addOption('status', 's', InputOption::VALUE_NONE, 'returns lock status of the file')
			->addArgument('file_id', InputArgument::OPTIONAL, 'Id of the locked file', 0)
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
	 * @throws NotFileException
	 * @throws InvalidPathException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$fileId = (int)$input->getArgument('file_id');
		$userId = $input->getArgument('user_id');

		try {
			$this->uninstallApp($input, $output);

			if ($fileId === 0) {
				throw new InvalidArgumentException('Not enough arguments (missing: "file_id")');
			}

			$this->getStatus($input, $output, $fileId);
			$this->unlockFile($input, $output, $fileId);
		} catch (SuccessException $e) {
			return 0;
		}

		if ($userId === '') {
			throw new InvalidArgumentException('\'Not enough arguments (missing: "user_id")');
		}

		$this->lockFile($input, $output, $fileId, $userId);

		return 0;
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
				'File #' . $fileId . ' is <comment>locked</comment> by ' . $lock->getOwner()
			);
			$output->writeln(
				' - Locked at: ' . date('c', $lock->getCreatedAt())
			);
			if ($lock->getETA() !== FileLock::ETA_INFINITE) {
				$output->writeln(
					' - Expiry in seconds: ' . $lock->getETA()
				);
			}
		} catch (LockNotFoundException $e) {
			$output->writeln('File #' . $fileId . ' is <info>not locked<info>');
		}

		throw new SuccessException();
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param int $fileId
	 * @param string|null $userId
	 *
	 * @throws InvalidPathException
	 * @throws NoUserException
	 * @throws NotFileException
	 * @throws NotFoundException
	 */
	private function lockFile(InputInterface $input, OutputInterface $output, int $fileId, ?string $userId) {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new NoUserException("Unknown user '" . $userId . "'");
		}

		$file = $this->fileService->getFileFromId($user->getUID(), $fileId);

		$output->writeln('<info>locking ' . $file->getName() . ' to ' . $userId . '</info>');
		$this->lockService->lock(new LockContext(
			$file, ILock::TYPE_USER, $userId
		));
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param int $fileId
	 *
	 * @throws SuccessException
	 * @throws UnauthorizedUnlockException
	 */
	private function unlockFile(InputInterface $input, OutputInterface $output, int $fileId) {
		if (!$input->getOption('unlock')) {
			return;
		}

		$output->writeln('<info>unlocking File #' . $fileId);
		try {
			$this->lockService->unlockFile($fileId, $input->getArgument('user_id'), true);
		} catch (LockNotFoundException $e) {
		}

		throw new SuccessException();
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws SuccessException
	 */
	private function uninstallApp(InputInterface $input, OutputInterface $output) {
		if (!$input->getOption('uninstall')) {
			return;
		}

		$helper = $this->getHelper('question');
		$output->writeln(
			'<error>Beware, this operation will uninstall the FilesLock App and delete all locks.</error>'
		);
		$output->writeln('');
		$question = new ConfirmationQuestion(
			'<info>Do you confirm this operation?</info> (y/N) ', false, '/^(y|Y)/i'
		);

		if (!$helper->ask($input, $output, $question)) {
			$output->writeln('operation cancelled');
			throw new SuccessException();
		}

		$this->locksRequest->uninstall();
		$this->configService->unsetAppConfig();
		$output->writeln('<comment>FilesLock App fully uninstalled.</comment>');

		throw new SuccessException();
	}
}
