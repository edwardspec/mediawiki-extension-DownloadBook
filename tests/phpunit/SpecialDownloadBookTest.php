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
use MediaWiki\Shell\Command;
use MediaWiki\Shell\CommandFactory;
use MWException;
use Shellbox\Command\UnboxedExecutor;
use Shellbox\Command\UnboxedResult;
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
		$format = 'something-like-pdf';

		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render',
			'metabook' => '{}',
			'writer' => $format
		] ), true );
		$this->assertSame( [ 'collection_id' => $expectedId ], $ret );

		// Verify that state of this task is "pending",
		// which should be the case, because DeferredUpdates haven't been called yet.
		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render_status',
			'collection_id' => $expectedId
		] ), true );
		$this->assertSame( [ 'state' => 'pending' ], $ret );

		// Mock the external command.
		$expectedCommand = '/dev/null/there/is/no/such/command -i {INPUT} -o {OUTPUT} ' .
			'--meta.creator={METADATA:creator} --meta.title={METADATA:title}';
		$this->overrideConfigValue( 'DownloadBookConvertCommand', [
			$format => $expectedCommand
		] );

		$mockedOutput = 'Invoked command will print this text.';
		$this->mockShellCommand( function ( $argv ) use ( $expectedCommand ) {
			$this->assertCount( 7, $argv, 'argv.count' );

			$inputFilename = $argv[2];
			$outputFilename = $argv[4];

			$this->assertSame( '/dev/null/there/is/no/such/command', $argv[0] );
			$this->assertSame( '-i', $argv[1] );
			$this->assertFileExists( $inputFilename, 'Input tempfile doesn\'t exist.' );
			$this->assertSame( '-o', $argv[3] );
			$this->assertFileExists( $outputFilename, 'Output tempfile doesn\'t exist.' );
		} );

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

	/**
	 * Intercept invocation of external command via ShellCommandFactory.
	 * Instead of executing the command, run $callback($argv) with $argv of that command.
	 *
	 * @param callable(string[]):void $callback Called when command is executed.
	 */
	public function mockShellCommand( $callback ) {
		$executor = $this->createMock( UnboxedExecutor::class );
		$executor->expects( $this->once() )->method( 'execute' )->willReturnCallback( function ( Command $command ) use ( $callback ) {
			$argv = $command->getSyntaxInfo()->getLiteralArgv();
			$this->assertNotNull( $argv, 'command.argv' );

			$callback( $argv );

			$result = new UnboxedResult();
			return $result->exitCode( 0 );
		} );

		$commandFactory = $this->createMock( CommandFactory::class );
		$commandFactory->expects( $this->once() )->method( 'create' )->willReturn( new Command( $executor ) );
		$this->setService( 'ShellCommandFactory', $commandFactory );
	}
}
