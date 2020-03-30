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

use DeferredUpdates;
use FileBackend;
use Html;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Shell\Shell;
use RepoGroup;
use RequestContext;
use SpecialPage;
use TempFSFile;
use Title;
use UploadStashException;
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
	 * @var LoggerFactory
	 */
	protected $logger;

	/**
	 * @param int $id
	 */
	protected function __construct( $id ) {
		$this->id = $id;
		$this->logger = LoggerFactory::getInstance( 'DownloadBook' );
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
	 * @param string $newFormat One of the keys in $wgDownloadBookConvertCommand array.
	 * @return int Value of collection_id (to be returned to Extension:Collection).
	 */
	public static function createNew( array $metabook, $newFormat ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert( 'bookrenderingtask', [
			'brt_timestamp' => $dbw->timestamp(),
			'brt_state' => self::STATE_PENDING,
			'brt_stash_key' => null
		], __METHOD__ );

		$id = $dbw->insertId();

		// Conversion itself can take a long time for large Collections,
		// so we marked state as PENDING and will later mark it as either FINISHED or FAILED
		// when startRendering() is completed.
		// Note: Extension:Collection itself has "check status, wait and retry" logic.
		DeferredUpdates::addCallableUpdate( function () use ( $id, $metabook, $newFormat ) {
			$task = new self( $id );
			$task->startRendering( $metabook, $newFormat );
		} );

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
		if ( !$row  ) {
			$this->logger->warning( 'getRenderStatus(): task #' . $this->id . ' not found.' );
			return [ 'state' => 'failed' ];
		}


		if ( $row->state == self::STATE_FAILED ) {
			$this->logger->warning( 'getRenderStatus(): found task #' . $this->id .
				', its state is FAILED.' );
			return [ 'state' => 'failed' ];
		}

		if ( $row->state == self::STATE_PENDING ) {
			$this->logger->warning( 'getRenderStatus(): found task #' . $this->id .
				', its state is PENDING: conversion started, but not completed (yet?).' );
			return [ 'state' => 'pending' ];
		}

		if ( $row->state == self::STATE_FINISHED && $row->stash_key ) {
			$this->logger->warning( 'getRenderStatus(): found task #' . $this->id .
				', its state is FINISHED: conversion was successful.' );

			// If the rendering is finished, then the resulting file is stored
			// in the stash.
			try {
				$file = $this->getUploadStash()->getFile( $row->stash_key );
			} catch ( UploadStashException $e ) {
				$this->logger->error( 'Failed to load #' . $this->id .
					' from the UploadStash: ' . (string)$e );
				return [ 'state' => 'failed' ];
			}

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
		$this->logger->error( 'getRenderStatus(): found task #' . $this->id .
			' with unknown state=[' . $row->state . ']' );
		return [ 'state' => 'failed' ];
	}

	/**
	 * Print contents of resulting file to STDOUT as a fully formatted HTTP response.
	 */
	public function stream() {
		$this->logger->debug( "[BookRenderingTask] Going to stream #" . $this->id );

		$dbr = wfGetDB( DB_REPLICA );
		$stashKey = $dbr->selectField( 'bookrenderingtask', 'brt_stash_key',
			[ 'brt_id' => $this->id ],
			__METHOD__
		);
		if ( !$stashKey ) {
			$this->logger->error( '[BookRenderingTask] stream(#' . $this->id . '): ' .
				'stashKey not found in the database.' );
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
	 */
	protected function startRendering( array $metabook, $newFormat ) {
		$this->logger->debug( "[BookRenderingTask] Going to render #" . $this->id .
			", newFormat=[$newFormat]." );

		$bookTitle = $metabook['title'] ?? '';
		$bookSubtitle = $metabook['subtitle'] ?? '';
		$items = $metabook['items'] ?? [];

		$html = '';
		$html .= Html::openElement( 'html' );
		if ( $bookTitle ) {
			$html .= Html::openElement( 'head' );
			$html .= Html::element( 'title', null, $bookTitle );
			$html .= Html::closeElement( 'head' );
		}

		$html .= Html::openElement( 'body' );

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

		$html .= Html::closeElement( 'body' );
		$html .= Html::closeElement( 'html' );

		// Do the actual rendering of $html by calling external utility like "pandoc"
		$tmpFile = $this->convertHtmlTo( $html, $newFormat );
		if ( !$tmpFile ) {
			$this->logger->error( '[BookRenderingTask] Failed to convert #' . $this->id . ' into ' . $newFormat );
			$this->changeState( self::STATE_FAILED );
			return;
		}

		$stash = $this->getUploadStash();
		$stashFile = null;
		try {
			$stashFile = $stash->stashFile( $tmpFile->getPath() );
		} catch ( UploadStashException $e ) {
			$this->logger->error( '[BookRenderingTask] Failed to save #' . $this->id .
				' into the UploadStash: ' . (string)$e );
		}

		if ( !$stashFile ) {
			$this->changeState( self::STATE_FAILED );
			return;
		}

		$stashKey = $stashFile->getFileKey();
		$this->logger->debug( '[BookRenderingTask] Successfully converted #' . $this->id . ": stashKey=$stashKey" );

		$this->changeState( self::STATE_FINISHED, $stashKey );
	}

	/**
	 * Convert HTML into some other format, e.g. PDF.
	 * @param string $html
	 * @param string $newFormat Name of format, e.g. "pdf" or "epub"
	 * @return TempFSFile|false Temporary file with results (if successful) or false.
	 */
	protected function convertHtmlTo( $html, $newFormat ) {
		global $wgDownloadBookConvertCommand, $wgDownloadBookFileExtension;

		$newFormat = strtolower( $newFormat );
		$command = $wgDownloadBookConvertCommand[$newFormat] ?? '';
		if ( !$command ) {
			$this->logger->error( "No conversion command for $newFormat" );
			return false;
		}

		$fileExtension = $wgDownloadBookFileExtension[$newFormat] ?? $newFormat;

		$inputFile = TempFSFile::factory( 'toconvert', 'html' );
		$inputPath = $inputFile->getPath();
		file_put_contents( $inputPath, $html );

		$outputFile = TempFSFile::factory( 'converted', $fileExtension );
		$outputPath = $outputFile->getPath();

		$command = str_replace(
			[ '{INPUT}', '{OUTPUT}' ],
			[ $inputPath, $outputPath ],
			$wgDownloadBookConvertCommand[$newFormat]
		);

		$this->logger->debug( "[BookRenderingTask] Attempting to convert HTML=(" . strlen( $html ) . " bytes omitted) into [$newFormat]..." );

		// Workaround for "pandoc" trying to use current directory
		// (to which it doesn't have write access) for its own temporary files.
		$currentDirectory = getcwd();
		chdir( wfTempDir() );

		$limits = [ 'memory' => -1, 'filesize' => -1, 'time' => -1, 'walltime' => -1 ];
		$ret = Shell::command( [] )
			->unsafeParams( explode( ' ', $command ) )
			->limits( $limits )
			//->restrict( Shell::NO_ROOT )
			->execute();

		chdir( $currentDirectory );

		if ( $ret->getExitCode() != 0 ) {
			$this->logger->error( "Conversion command has failed: command=[$command], " .
				"output=[" . $ret->getStdout() . "], stderr=[" . $ret->getStderr() . "]" );
			return false;
		}

		$this->logger->debug( '[BookRenderingTask] Generated successfully: outputFile contains ' .
			filesize( $outputPath ) . ' bytes.' );

		return $outputFile;
	}
}
