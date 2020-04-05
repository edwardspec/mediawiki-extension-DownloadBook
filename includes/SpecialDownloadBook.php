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
 * Special:DownloadBook - allows to download book (from Extension:Collection) as PDF, ePub, etc.
 */

namespace MediaWiki\DownloadBook;

use FormatJson;
use MediaWiki\Logger\LoggerFactory;
use MWException;
use UnlistedSpecialPage;

class SpecialDownloadBook extends UnlistedSpecialPage {
	public function __construct() {
		parent::__construct( 'DownloadBook' );
	}

	/**
	 * @param string $par @phan-unused-param
	 */
	public function execute( $par = '' ) {
		$request = $this->getRequest();
		$command = $request->getVal( 'command' );
		$collectionId = $request->getInt( 'collection_id' );

		if ( $request->getVal( 'stream' ) ) {
			$task = BookRenderingTask::newFromId( $collectionId );
			$task->stream();
			return;
		}

		$logger = LoggerFactory::getInstance( 'DownloadBook' );
		$logger->debug( '[Special:DownloadBook] Received API request: ' .
			FormatJson::encode( $request->getValues() ) );

		if ( $command === 'render_status' ) {
			// Report whether the rendering is finished or not.
			// If it is, result will include URL to download the resulting file
			$task = BookRenderingTask::newFromId( $collectionId );
			$ret = $task->getRenderStatus();
		} elseif ( $command === 'render' ) {
			$newFormat = $request->getVal( 'writer', 'rl' );

			$json = $request->getVal( 'metabook', '' );
			$status = FormatJson::parse( $json, FormatJson::FORCE_ASSOC );
			if ( !$status->isOK() ) {
				$logger->error( '[Special:DownloadBook] command=render: Malformed metabook parameter.' );
				throw new MWException( 'Malformed metabook parameter.' );
			}
			$metabook = $status->value;

			// Start new rendering
			$collectionId = BookRenderingTask::createNew( $metabook, $newFormat );
			$ret = [ 'collection_id' => $collectionId ];
		} else {
			$logger->error( "[Special:DownloadBook] Unknown command: [$command]" );
			throw new MWException( 'Unknown command.' );
		}

		$logger->debug( "[Special:DownloadBook] Sending API response to command=$command: " .
			FormatJson::encode( $ret ) );

		$this->getOutput()->disable();
		$request->response()->statusHeader( 200 );
		$request->response()->header( 'Content-Type: application/json' );
		echo FormatJson::encode( $ret );
	}
}
