<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FilesLock\Command;

use OCA\FilesLock\Db\LocksRequest;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Exceptions\NotFileException;
use OCA\FilesLock\Exceptions\UnauthorizedUnlockException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\FileService;
use OCA\FilesLock\Service\LockService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Console\Attribute\Argument;
use OCP\Console\Attribute\AsCommand;
use OCP\Console\Attribute\Option;
use OCP\Console\ExitCode;
use OCP\Console\IInput;
use OCP\Console\IOutput;
use OCP\Files\InvalidPathException;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\LockContext;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\User\Exceptions\UserNotFoundException;
use Symfony\Component\Console\Exception\InvalidArgumentException;

#[AsCommand(
	name: 'files:lock',
	description: 'Lock, unlock, or inspect a file lock',
	help: <<<'HELP'
<info>Lock a file:</info>
  <comment>occ files:lock &lt;file_id&gt; &lt;user_id&gt;</comment>

<info>Show a file lock's status:</info>
  <comment>occ files:lock --status &lt;file_id&gt;</comment>

<info>Forcibly unlock a file:</info>
  <comment>occ files:lock --unlock &lt;file_id&gt; [&lt;user_id&gt;]</comment>

For app-owned locks, provide a user ID that has access to the file. This can be
needed for files stored in Groupfolders:
  <comment>occ files:lock --unlock &lt;file_id&gt; &lt;user_id&gt;</comment>

<info>Uninstall the app and delete all locks:</info>
  <comment>occ files:lock --uninstall</comment>
HELP
)]
class Lock {
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly LocksRequest $locksRequest,
		private readonly FileService $fileService,
		private readonly LockService $lockService,
		private readonly IAppConfig $appConfig,
	) {
	}

	/**
	 * @throws NotFoundException
	 * @throws UnauthorizedUnlockException
	 * @throws NotFileException
	 * @throws InvalidPathException
	 */
	public function __invoke(
		IOutput $output,
		IInput $input,
		#[Argument(description: 'ID of the file to lock, unlock, or inspect', name: 'file_id')]
		?string $fileId = null,
		#[Argument(description: 'Lock owner when locking; user with file access when unlocking an app-owned lock', name: 'user_id')]
		?string $userId = null,
		#[Option(description: 'Fully uninstall the app from your Nextcloud')]
		bool $uninstall = false,
		#[Option(description: 'Show the lock status of a file', shortcut: 's')]
		bool $status = false,
		#[Option(description: 'Forcibly unlock a file', shortcut: 'u')]
		bool $unlock = false,
	): ExitCode {
		if ($uninstall) {
			return $this->uninstallApp($input, $output);
		}

		$fileId = (int)$fileId;

		if ($fileId <= 0) {
			$output->writeln('<error>Not enough arguments (missing: "file_id")</error>');
			return ExitCode::Invalid;
		}

		if ($status === true) {
			return $this->getStatus($output, $fileId);
		}

		if ($unlock === true) {
			return $this->unlockFile($output, $userId, $fileId);
		}

		if ($userId === null || $userId === '') {
			throw new InvalidArgumentException('Not enough arguments (missing: "user_id")');
		}

		return $this->lockFile($output, $fileId, $userId);
	}

	private function getStatus(IOutput $output, int $fileId): ExitCode {
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
		} catch (LockNotFoundException) {
			$output->writeln('File #' . $fileId . ' is <info>not locked</info>');
		}

		return ExitCode::Success;
	}

	/**
	 * @throws InvalidPathException
	 * @throws NotFileException
	 * @throws NotFoundException
	 * @throws UserNotFoundException
	 */
	private function lockFile(IOutput $output, int $fileId, string $userId): ExitCode {
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new UserNotFoundException("Unknown user '" . $userId . "'");
		}

		$file = $this->fileService->getFileFromId($user->getUID(), $fileId);

		$output->writeln('<info>locking ' . $file->getName() . ' to ' . $userId . '</info>');
		$this->lockService->lock(new LockContext(
			$file, ILock::TYPE_USER, $userId
		));
		return ExitCode::Success;
	}

	/**
	 * @throws UnauthorizedUnlockException
	 */
	private function unlockFile(IOutput $output, ?string $userId, int $fileId): ExitCode {
		try {
			$this->lockService->unlockFile($fileId, $userId, true);
			$output->writeln('<info>Unlocked file #' . $fileId . '</info>');
		} catch (LockNotFoundException) {
			$output->writeln('<comment>File #' . $fileId . ' was already unlocked</comment>');
		}

		return ExitCode::Success;
	}

	private function uninstallApp(IInput $input, IOutput $output): ExitCode {
		$output->writeln(
			'<error>Beware, this operation will uninstall the FilesLock App and delete all locks.</error>'
		);

		$confirmed = $input->confirm('<info>Do you confirm this operation?</info> (y/N)', false);
		if (!$confirmed) {
			$output->writeln('Operation cancelled');
			return ExitCode::Success;
		}

		$this->locksRequest->uninstall();
		$this->appConfig->deleteAppValues();
		$output->writeln('<comment>FilesLock App fully uninstalled.</comment>');

		return ExitCode::Success;
	}
}
