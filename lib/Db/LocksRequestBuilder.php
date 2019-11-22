<?php declare(strict_types=1);


/**
 * Files_Lock - Temporary Files Lock
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Maxence Lange <maxence@artificial-owl.com>
 * @copyright 2019
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


namespace OCA\FilesLock\Db;


use daita\MySmallPhpTools\Exceptions\RowNotFoundException;
use daita\MySmallPhpTools\Traits\TArrayTools;
use OCA\FilesLock\Exceptions\LockNotFoundException;
use OCA\FilesLock\Model\FileLock;
use OCA\FilesLock\Service\ConfigService;


/**
 * Class LocksRequestBuilder
 *
 * @package OCA\FilesLock\Db
 */
class LocksRequestBuilder extends CoreRequestBuilder {


	use TArrayTools;


	/**
	 * Base of the Sql Insert request
	 *
	 * @return LocksQueryBuilder
	 */
	protected function getLocksInsertSql(): LocksQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->insert(self::TABLE_LOCKS);

		return $qb;
	}


	/**
	 * Base of the Sql Update request
	 *
	 * @return LocksQueryBuilder
	 */
	protected function getLocksUpdateSql(): LocksQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->update(self::TABLE_LOCKS);

		return $qb;
	}


	/**
	 * Base of the Sql Select request for Shares
	 *
	 * @return LocksQueryBuilder
	 */
	protected function getLocksSelectSql(): LocksQueryBuilder {
		$qb = $this->getQueryBuilder();

		/** @noinspection PhpMethodParametersCountMismatchInspection */
		$qb->select('l.id', 'l.user_id', 'l.file_id', 'l.token', 'l.creation')
		   ->from(self::TABLE_LOCKS, 'l');

		$qb->setDefaultSelectAlias('l');

		return $qb;
	}


	/**
	 * Base of the Sql Delete request
	 *
	 * @return LocksQueryBuilder
	 */
	protected function getLocksDeleteSql(): LocksQueryBuilder {
		$qb = $this->getQueryBuilder();
		$qb->delete(self::TABLE_LOCKS);

		return $qb;
	}


	/**
	 * @param LocksQueryBuilder $qb
	 *
	 * @return FileLock
	 * @throws LockNotFoundException
	 */
	protected function getLockFromRequest(LocksQueryBuilder $qb): FileLock {
		/** @var FileLock $result */
		try {
			$result = $qb->getRow([$this, 'parseLockSelectSql']);
		} catch (RowNotFoundException $e) {
			throw new LockNotFoundException($e->getMessage());
		}

		return $result;
	}


	/**
	 * @param LocksQueryBuilder $qb
	 *
	 * @return FileLock[]
	 */
	public function getLocksFromRequest(LocksQueryBuilder $qb): array {
		/** @var FileLock[] $result */
		$result = $qb->getRows([$this, 'parseLockSelectSql']);

		return $result;
	}


	/**
	 * @param array $data
	 *
	 * @return FileLock
	 */
	public function parseLockSelectSql(array $data): FileLock {
		$lock = new FileLock($this->configService->getTimeoutSeconds());
		$lock->importFromDatabase($data);

		return $lock;
	}

}

