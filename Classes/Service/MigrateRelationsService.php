<?php
namespace TYPO3\CMS\DamFalmigration\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2012 Benjamin Mack <benni@typo3.org>
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *  A copy is found in the textfile GPL.txt and important notices to the license
 *  from the author is found in LICENSE.txt distributed with these scripts.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use B13\DamFalmigration\Controller\DamMigrationCommandController;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Migrate DAM relations to FAL relations
 * right now this is dam_ttcontent, dam_uploads
 *
 * @author      Benjamin Mack <benni@typo3.org>
 */
class MigrateRelationsService extends AbstractService {

	/**
	 * @var \TYPO3\CMS\Core\Database\ReferenceIndex
	 * @inject
	 */
	protected $referenceIndex;

	/**
	 * main function
	 *
	 * @param DamMigrationCommandController $parent Used to log output to
	 *    console
	 *
	 * @throws \Exception
	 * @return FlashMessage
	 */
	public function execute($parent) {
		$this->setParent($parent);
		$this->parent->headerMessage(LocalizationUtility::translate('migrateRelationsCommand', 'dam_falmigration'));
		if ($this->isTableAvailable('tx_dam_mm_ref')) {
			$numberImportedRelationsByContentElement = array();
			$damRelations = $this->getDamReferencesWhereSysFileExists();
			foreach ($damRelations as $damRelation) {
				$pid = $this->getPidOfForeignRecord($damRelation);
				$insertData = array(
						'pid' => ($pid === NULL) ? 0 : $pid,
						'tstamp' => time(),
						'crdate' => time(),
						'cruser_id' => $GLOBALS['BE_USER']->user['uid'],
						'sorting_foreign' => $damRelation['sorting_foreign'],
						'uid_local' => $damRelation['sys_file_uid'],
						'uid_foreign' => $damRelation['uid_foreign'],
						'tablenames' => $damRelation['tablenames'],
						'fieldname' => $this->getColForFieldName($damRelation),
						'table_local' => 'sys_file',
						'title' => $damRelation['title'],
						'description' => $damRelation['description'],
						'alternative' => $damRelation['alternative'],
				);

				// we need an array holding the already migrated file-relations to choose the right line of the imagecaption-field.
				if ($insertData['tablenames'] == 'tt_content' && ($insertData['fieldname'] == 'media' || $insertData['fieldname'] == 'image')) {
					$numberImportedRelationsByContentElement[$insertData['uid_foreign']]++;
				}

				if (!$this->checkIfSysFileRelationExists($damRelation)) {
					$this->database->exec_INSERTquery(
							'sys_file_reference',
							$insertData
					);
					$newRelationsRecordUid = $this->database->sql_insert_id();
					$this->updateReferenceIndex($newRelationsRecordUid);

					// pageLayoutView-object needs image to be set something higher than 0
					if ($damRelation['tablenames'] === 'tt_content' || $damRelation['tablenames'] === 'pages') {
						if ($insertData['fieldname'] === 'image') {
							$tcaConfig = $GLOBALS['TCA']['tt_content']['columns']['image']['config'];
							if ($tcaConfig['type'] === 'inline') {
								$this->database->exec_UPDATEquery(
										'tt_content',
										'uid = ' . $damRelation['uid_foreign'],
										array('image' => 1)
								);
							}

							// migrate image_links from tt_content.
							$linkFromContentRecord = $this->database->exec_SELECTgetSingleRow(
									'image_link,imagecaption',
									'tt_content',
									'uid = ' . $damRelation['uid_foreign']
							);
							if (!empty($linkFromContentRecord)) {
								$imageLinks = explode(chr(10), $linkFromContentRecord['image_link']);
								$imageCaptions = explode(chr(10), $linkFromContentRecord['imagecaption']);
								$this->database->exec_UPDATEquery(
										'sys_file_reference',
										'uid = ' . $newRelationsRecordUid,
										array(
												'link' => $imageLinks[$numberImportedRelationsByContentElement[$insertData['uid_foreign']] - 1],
												'title' => $imageCaptions[$numberImportedRelationsByContentElement[$insertData['uid_foreign']] - 1]

										)
								);
							}
						} elseif ($insertData['fieldname'] === 'media') {
							// migrate captions from tt_content upload elements
							$linkFromContentRecord = $this->database->exec_SELECTgetSingleRow(
									'imagecaption',
									'tt_content',
									'uid = ' . $damRelation['uid_foreign']
							);


							if (!empty($linkFromContentRecord)) {
								$imageCaptions = \TYPO3\CMS\Core\Utility\GeneralUtility::trimExplode(chr(10), $linkFromContentRecord['imagecaption']);
								$this->database->exec_UPDATEquery(
										'sys_file_reference',
										'uid = ' . $newRelationsRecordUid,
										array(
												'title' => $imageCaptions[$numberImportedRelationsByContentElement[$insertData['uid_foreign']] - 1]
										)
								);
							}
						}
					}
					$this->amountOfMigratedRecords++;
				}
			}
			return $this->getResultMessage();
		} else {
			throw new \Exception('Extension tx_dam and dam_ttcontent is not installed. So there is nothing to migrate.');
		}
	}

	/**
	 * get pid of foreign record
	 * this is needed by sys_file_reference records
	 *
	 * @param array $damRelation
	 * @return integer
	 */
	protected function getPidOfForeignRecord(array $damRelation) {
		$record = $GLOBALS['TYPO3_DB']->exec_SELECTgetSingleRow(
				'pid',
				$damRelation['tablenames'],
				'uid=' . (int)$damRelation['uid_foreign']
		);
		return $record['pid'] ?: 0;
	}

	/**
	 * After a migration of tx_dam -> sys_file the col _migrateddamuid is filled with dam uid
	 * Now we can search in dam relations for dam records which have already been migrated to sys_file
	 *
	 * @throws \Exception
	 * @return array
	 */
	protected function getDamReferencesWhereSysFileExists() {
		$rows = $this->database->exec_SELECTgetRows(
				'MM.*, SF.uid as sys_file_uid, MD.title, MD.description, MD.alternative',
				'tx_dam_mm_ref MM, sys_file SF, sys_file_metadata MD',
				'MD.file = SF.uid AND SF._migrateddamuid = MM.uid_local',
				'',
				'MM.sorting ASC'
		);
		if ($rows === NULL) {
			throw new \Exception('SQL-Error in getDamReferencesWhereSysFileExists()', 1382353670);
		} elseif (count($rows) === 0) {
			throw new \Exception('There are no migrated dam records in sys_file. Please start to migrate DAM -> sys_file first. Or, maybe there are no dam records to migrate', 1382355647);
		} else return $rows;
	}

	/**
	 * check if a sys_file_reference already exists
	 *
	 * @param array $damRelation
	 * @return boolean
	 */
	protected function checkIfSysFileRelationExists(array $damRelation) {
		$amountOfExistingRecords = $this->database->exec_SELECTcountRows(
				'*',
				'sys_file_reference',
				'uid_local = ' . $damRelation['sys_file_uid'] .
				' AND uid_foreign = ' . $damRelation['uid_foreign'] .
				' AND tablenames = ' . $this->database->fullQuoteStr($damRelation['tablenames'], 'sys_file_reference') .
				' AND fieldname = ' . $this->database->fullQuoteStr($this->getColForFieldName($damRelation), 'sys_file_reference') .
				' AND deleted = 0'
		);
		if ($amountOfExistingRecords) {
			return TRUE;
		} else {
			return FALSE;
		}
	}

	/**
	 * col for fieldname was saved in col "ident"
	 * But: If dam_ttcontent is installed fieldName is "image" for images and "media" for uploads
	 *
	 * @param array $damRelation
	 * @return string
	 */
	protected function getColForFieldName(array $damRelation) {
		if ($damRelation['tablenames'] == 'tt_content' && $damRelation['ident'] == 'tx_damttcontent_files') {
			$fieldName = 'image';
		} elseif ($damRelation['tablenames'] == 'tt_content' && ($damRelation['ident'] == 'tx_damttcontent_files_upload' || $damRelation['ident'] == 'tx_damfilelinks_filelinks')) {
			$fieldName = 'media';
		} elseif ($damRelation['tablenames'] == 'pages' && $damRelation['ident'] == 'tx_dampages_files') {
			$fieldName = 'media';
		} else {
			$fieldName = $damRelation['ident'];
		}
		return $fieldName;
	}

	/**
	 * update reference index
	 *
	 * @param integer $uid
	 * @return void
	 */
	protected function updateReferenceIndex($uid) {
		$this->referenceIndex->updateRefIndexTable('sys_file_reference', $uid);
	}

}
