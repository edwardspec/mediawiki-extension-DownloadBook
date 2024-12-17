<?php

/*
	Extension:DownloadBook - MediaWiki extension.
	Copyright (C) 2024 Edward Chernenko.

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
 * Integration test for [[Special:DownloadBook]] (API expected by Extension:Collections).
 */

namespace MediaWiki\DownloadBook;

use DeferredUpdates;
use FauxRequest;
use FormatJson;
use MWException;
use SpecialPageTestBase;

/**
 * @covers MediaWiki\DownloadBook\SpecialDownloadBook
 * @group Database
 */
class SpecialDownloadBookTest extends SpecialPageTestBase {
	protected function newSpecialPage() {
		return new SpecialDownloadBook();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->tablesUsed[] = 'bookrenderingtask';
	}

	/**
	 * Checks the result when called without ?command=.
	 */
	public function testErrorNoCommand() {
		$this->expectExceptionObject( new MWException( 'Unknown command.' ) );
		$this->runSpecial( [] );
	}

	/**
	 * Checks the result when called with unsupported ?command=.
	 */
	public function testErrorUnknownCommand() {
		$this->expectExceptionObject( new MWException( 'Unknown command.' ) );
		$this->runSpecial( [ 'command' => 'makesalad' ] );
	}

	/**
	 * Checks the result when command=render is called with invalid JSON as metabook.
	 */
	public function testErrorRenderInvalidJson() {
		$this->expectExceptionObject( new MWException( 'Malformed metabook parameter.' ) );
		$this->runSpecial( [
			'command' => 'render',
			'metabook' => 'Invalid; JSON;'
		] );
	}

	/**
	 * Checks the result of command=render.
	 */
	public function testRender() {
		// Don't run DeferredUpdates until we check [ 'state' => 'pending' ].
		$updatesDelayed = DeferredUpdates::preventOpportunisticUpdates();
		$expectedId = 1;

		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render',
			'metabook' => '{}'
		] ), true );
		$this->assertSame( [ 'collection_id' => $expectedId ], $ret );

		// Verify that state of this task is "pending",
		// which should be the case, because DeferredUpdates haven't been called yet.
		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render_status',
			'collection_id' => $expectedId
		] ), true );
		$this->assertSame( [ 'state' => 'pending' ], $ret );

		// Run the pending DeferredUpdates.
		$updatesDelayed = null;
		DeferredUpdates::tryOpportunisticExecute();

		// Verify that the task was marked as "failed" by DeferredUpdates.
		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render_status',
			'collection_id' => $expectedId
		] ), true );
		$this->assertSame( [ 'state' => 'failed' ], $ret );

		// TODO: supply valid metabook
		// TODO: precreate articles that would be included into the metabook
		// TODO: mock ShellCommandFactory service to analyze "what command got called"
		// and to return mocked result of "HTML-to-output-format" conversion.
	}

	// TODO: integration test for [ 'command' => 'render' ]
	// TODO: integration test for [ 'command' => 'render_status' ]
	// TODO: integration test for [ 'command' => 'stream' ]

	/**
	 * Render Special:DownloadBook.
	 * @param array $query Query string parameters.
	 * @return HTML of the result.
	 */
	public function runSpecial( array $query ) {
		[ $html, ] = $this->executeSpecialPage( '', new FauxRequest( $query, false ) );
		return $html;
	}
}
