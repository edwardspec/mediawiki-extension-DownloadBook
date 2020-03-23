<?php

/*
	Extension:DownloadBook - MediaWiki extension.
	Copyright (C) 2020 Edward Chernenko.

	This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.
*/

/**
 * @file
 * Represents one task of background rendering of Book into PDF, ePub, etc.
 */

namespace MediaWiki\DownloadBook;

use FileBackend;
use Html;
use RepoGroup;
use RequestContext;
use SpecialPage;
use TempFSFile;
use Title;
use User;
use WikiPage;

class BookRenderingTask {
	const STATE_FAILED = 'failed';
	const STATE_FINISHED = 'finished';
	const STATE_PENDING = 'pending';

	/**
	 * @var int
	 */
	protected $id;

	/**
	 * @param int $id
	 */
	protected function __construct( $id ) {
		$this->id = $id;
	}

	/**
	 * @param int $id
	 * @return self
	 */
	public static function newFromId( $id ) {
		return new self( $id );
	}

	/**
	 * Start rendering a new collection.
	 * @param array $metabook Parameters of book, as supplied by Extension:Collection.
	 * @return int Value of collection_id (to be returned to Extension:Collection).
	 */
	public static function createNew( array $metabook ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'bookrenderingtask', [
			'brt_timestamp' => $dbw->timestamp(),
			'brt_state' => self::STATE_PENDING,
			'brt_stash_key' => null
		], __METHOD__ );

		$id = $dbw->insertId();

		$task = new self( $id );
		$task->startRendering( $metabook );

		return $id;
	}

	/**
	 * @param string $newState
	 * @param string|null $newStashKey
	 */
	protected function changeState( $newState, $newStashKey = null ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'bookrenderingtask',
			[ 'brt_state' => $newState, 'brt_stash_key' => $newStashKey ],
			[ 'brt_id' => $this->id ],
			__METHOD__
		);
	}

	/**
	 * Get UploadStash where the results of rendering (e.g. .PDF files) are stored.
	 * @return UploadStash
	 */
	protected function getUploadStash() {
		$user = User::newSystemUser( 'DownloadBookStash', [ 'steal' => true ] );
		return RepoGroup::singleton()->getLocalRepo()->getUploadStash( $user );
	}

	/**
	 * Get status of rendering in API format, as expected by Extension:Collection.
	 * @return array
	 */
	public function getRenderStatus() {
		$dbr = wfGetDB( DB_REPLICA );
		$row = $dbr->selectRow( 'bookrenderingtask',
			[ 'brt_state AS state' ,'brt_stash_key AS stash_key' ],
			[ 'brt_id' => $this->id ],
			__METHOD__
		);
		if ( !$row || $row->state == self::STATE_FAILED ) {
			return [ 'state' => 'failed' ];
		}

		if ( $row->state == self::STATE_PENDING ) {
			return [ 'state' => 'pending' ];
		}

		if ( $row->state == self::STATE_FINISHED && $row->stash_key ) {
			wfDebugLog( 'DownloadBook', "getRenderStatus(): found finished task" );

			// If the rendering is finished, then the resulting file is stored
			// in the stash.
			$file = $this->getUploadStash()->getFile( $row->stash_key );

			return [
				'state' => 'finished',
				'url' => SpecialPage::getTitleFor( 'DownloadBook' )->getLocalURL( [
					'stream' => 1,
					'collection_id' => $this->id
				] ),
				'content_type' => $file->getMimeType(),
				'content_length' => $file->getSize(),
				'content_disposition' => FileBackend::makeContentDisposition( 'inline', $file->getName() )
			];
		}

		// Unknown state
		return [ 'state' => 'failed' ];
	}

	/**
	 * Print contents of resulting file to STDOUT as a fully formatted HTTP response.
	 */
	public function stream() {
		wfDebugLog( 'DownloadBook', "Going to stream #" . $this->id );

		$dbr = wfGetDB( DB_REPLICA );
		$stashKey = $dbr->selectField( 'bookrenderingtask', 'brt_stash_key',
			[ 'brt_id' => $this->id ],
			__METHOD__
		);
		if ( !$stashKey ) {
			throw new MWException( 'Rendered file is not available.' );
		}

		$file = $this->getUploadStash()->getFile( $stashKey );

		$headers = [];
		$headers[] = 'Content-Disposition: ' .
			FileBackend::makeContentDisposition( 'inline', $file->getName() );

		$repo = RepoGroup::singleton()->getLocalRepo();
		$repo->streamFileWithStatus( $file->getPath(), $headers );
	}

	/**
	 * @param array $metabook
	 * @param string $newFormat
	 * @return string collection_id
	 */
	protected function startRendering( array $metabook, $newFormat = 'pdf' ) {
		wfDebugLog( 'DownloadBook', "Going to render #" . $this->id );

		$bookTitle = $metabook['title'] ?? '';
		$bookSubtitle = $metabook['subtitle'] ?? '';
		$items = $metabook['items'] ?? [];

		$html = '';
		if ( $bookTitle ) {
			$html .= Html::element( 'h1', null, $bookTitle );
		}

		if ( $bookSubtitle ) {
			$html .= Html::element( 'h2', null, $bookSubtitle );
		}

		foreach ( $items as $item ) {
			$type = $item['type'] ?? '';
			if ( $type != 'article' ) {
				// type="chapter" is not yet supported.
				continue;
			}

			$title = Title::newFromText( $item['title'] ?? '' );
			if ( !$title ) {
				// Ignore invalid titles
				continue;
			}

			// Add parsed HTML of this article
			$page = WikiPage::factory( $title );
			$content = $page->getContent();
			if ( !$content ) {
				// Ignore nonexistent pages, etc.
				continue;
			}

			$popts = RequestContext::getMain()->getOutput()->parserOptions();
			$pout = $content->getParserOutput( $title, 0, $popts, true );

			$html .= Html::element( 'h1', null, $title->getFullText() );
			$html .= $pout->getText( [ 'enableSectionEditLinks' => false ] );
			$html .= "\n\n";
		}

		// TODO: do the actual rendering of $html by calling external utility like "pandoc"
		$tmpFile = $this->convertHtmlTo( $html, $newFormat );
		if ( !$tmpFile ) {
			wfDebugLog( 'DownloadBook', 'Failed to convert #' . $this->id . ' into ' . $newFormat );
			$this->changeState( self::STATE_FAILED );
			return;
		}

		$stash = $this->getUploadStash();
		$stashFile = $stash->stashFile( $tmpFile->getPath() );
		if ( !$stashFile ) {
			$this->changeState( self::STATE_FAILED );
			return;
		}

		wfDebugLog( 'DownloadBook', 'Successfully converted #' . $this->id );
		$this->changeState( self::STATE_FINISHED, $stashFile->getFileKey() );
	}

	/**
	 * Convert HTML into some other format, e.g. PDF.
	 * @param string $html
	 * @param string $newFormat Name of format, e.g. "pdf" or "epub"
	 * @return TempFSFile|false Temporary file with results (if successful) or false.
	 */
	protected function convertHtmlTo( $html, $newFormat ) {
		$tmpFile = TempFSFile::factory( 'converted' );
		file_put_contents( $tmpFile->getPath(), 'Some text' );

		return $tmpFile;
	}
}
