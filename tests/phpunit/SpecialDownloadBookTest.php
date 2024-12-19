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
use LocalRepo;
use MediaWiki\Shell\Command;
use MediaWiki\Shell\CommandFactory;
use MWException;
use RepoGroup;
use Shellbox\Command\UnboxedExecutor;
use Shellbox\Command\UnboxedResult;
use Shellbox\ShellParser\ShellParser;
use SpecialPage;
use SpecialPageTestBase;
use User;

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
	 * Checks the result of command=render when conversion command is missing or unusable.
	 */
	public function testErrorRenderNoCommand() {
		// Remove all known conversion commands.
		$this->overrideConfigValue( 'DownloadBookConvertCommand', [] );

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
	}

	/**
	 * Checks the result of command=render.
	 */
	public function testRender() {
		// Don't run DeferredUpdates until we check [ 'state' => 'pending' ].
		$updatesDelayed = DeferredUpdates::preventOpportunisticUpdates();
		$expectedId = 1;
		$format = 'something-like-pdf';

		// Precreate articles that would be included into the metabook
		$this->editPage( 'First included article', "Text of the '''first''' article" );
		$this->editPage( 'Second included article', "Second ''text'' in the book" );
		$this->editPage( 'Third included article', 'Text #3' );

		$expectedOutput = '<h1>Generated HTML of the requested metabook</h1> Some text.';
		$metabook = FormatJson::encode( [
			'title' => 'Title of collection',
			'subtitle' => 'Subtitle of collection',
			'items' => [
				[
					'type' => 'article',
					'title' => 'First included article'
				],
				[
					'type' => 'article',
					'title' => 'Second included article'
				],
				[
					'type' => 'article',
					'title' => 'Third included article'
				]
			]
		] );
		if ( version_compare( MW_VERSION, '1.42.0-alpha', '>=' ) ) {
			// MediaWiki 1.42+
			$expectedPoutTag = '<div class="mw-content-ltr mw-parser-output" lang="en" dir="ltr">';
		} else {
			// MediaWiki 1.39-1.41
			$expectedPoutTag = '<div class="mw-parser-output">';
		}
		$expectedHtmlInput = '<html><head><title>Title of collection</title></head><body>' .
			'<h2>Subtitle of collection</h2>' .
			'<h1>First included article</h1>' . $expectedPoutTag .
			"<p>Text of the <b>first</b> article\n</p></div>\n\n" .
			'<h1>Second included article</h1>' . $expectedPoutTag .
			"<p>Second <i>text</i> in the book\n</p></div>\n\n" .
			'<h1>Third included article</h1>' . $expectedPoutTag .
			"<p>Text #3\n</p></div>\n\n</body></html>";

		// Start rendering.
		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render',
			'metabook' => $metabook,
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
		$this->overrideConfigValue( 'DownloadBookConvertCommand', [
			$format => '/dev/null/there/is/no/such/command -i {INPUT} -o {OUTPUT} ' .
				'--meta.creator={METADATA:creator} --meta.title={METADATA:title}'
		] );

		$this->mockShellCommand( function ( $argv )
			use ( $metabook, $expectedHtmlInput, $expectedOutput )
		{
			$this->assertCount( 7, $argv, 'argv.count' );

			$inputFilename = $argv[2];
			$outputFilename = $argv[4];

			$this->assertSame( '/dev/null/there/is/no/such/command', $argv[0] );
			$this->assertSame( '-i', $argv[1] );
			$this->assertSame( '-o', $argv[3] );
			$this->assertSame( '--meta.creator=Default creator', $argv[5] );
			$this->assertSame( '--meta.title=Title of collection', $argv[6] );

			$this->assertFileExists( $inputFilename, 'Input tempfile doesn\'t exist.' );
			$this->assertSame( $expectedHtmlInput, file_get_contents( $inputFilename ) );
			$this->assertFileExists( $outputFilename, 'Output tempfile doesn\'t exist.' );

			file_put_contents( $outputFilename, $expectedOutput );
		} );

		// Run the pending DeferredUpdates.
		$updatesDelayed = null;
		DeferredUpdates::tryOpportunisticExecute();

		// Verify that the task was marked as "finished" by DeferredUpdates.
		$ret = FormatJson::decode( $this->runSpecial( [
			'command' => 'render_status',
			'collection_id' => $expectedId
		] ), true );

		$expectedUrl = SpecialPage::getTitleFor( 'DownloadBook' )->getLocalURL( [
			'stream' => 1,
			'collection_id' => $expectedId
		] );
		$this->assertSame( 'finished', $ret['state'] );
		$this->assertSame( $expectedUrl, $ret['url'] );
		$this->assertSame( 'text/plain', $ret['content_type'] );
		$this->assertSame( strlen( $expectedOutput ), $ret['content_length'],
			'Unexpected content_length of the result.' );

		$expectedDisposition = 'inline;filename*=UTF-8\'\'Title_of_collection.something-like-pdf';
		$this->assertSame( $expectedDisposition, $ret['content_disposition'] );

		// Mock RepoGroup service to verify that the result can be streamed.
		$realRepo = $this->getServiceContainer()->getRepoGroup()->getLocalRepo();
		$stash = $realRepo->getUploadStash(
			User::newSystemUser( 'DownloadBookStash' )
		);
		$repo = $this->createMock( LocalRepo::class );
		$repo->expects( $this->once() )->method( 'streamFileWithStatus' )->willReturnCallback(
			function ( $path, $headers ) use ( $expectedDisposition, $realRepo, $expectedOutput ) {
				$this->assertSame( [ "Content-Disposition: $expectedDisposition" ], $headers );

				// Here $path is mwstore:// URL (not a filename).
				$output = $realRepo->getBackend()->getFileContents( [ 'src' => $path ] );
				$this->assertSame( $expectedOutput, $output,
					'Unexpected file contents in streamFileWithStatus()' );
			}
		);
		$repo->expects( $this->any() )->method( 'getUploadStash' )->willReturn( $stash );

		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->expects( $this->any() )->method( 'getLocalRepo' )->willReturn( $repo );
		$this->setService( 'RepoGroup', $repoGroup );

		$ret = $this->runSpecial( [
			'stream' => 1,
			'collection_id' => $expectedId
		] );
		$this->assertSame( '', $ret, 'Unexpected output from stream=1.' );
	}

	/**
	 * Render Special:DownloadBook.
	 * @param array $query Query string parameters.
	 * @return HTML of the result.
	 */
	protected function runSpecial( array $query ) {
		[ $html, ] = $this->executeSpecialPage( '', new FauxRequest( $query, false ) );
		return $html;
	}

	/**
	 * Intercept invocation of external command via ShellCommandFactory.
	 * Instead of executing the command, run $callback($argv) with $argv of that command.
	 *
	 * @param callable(string[]):void $callback Called when command is executed.
	 */
	protected function mockShellCommand( $callback ) {
		$executor = $this->createMock( UnboxedExecutor::class );
		$executor->expects( $this->once() )->method( 'execute' )->willReturnCallback(
			function ( Command $command ) use ( $callback )
			{
				// MediaWiki 1.39 doesn't have $command->getSyntaxInfo()
				$syntaxInfo = ( new ShellParser() )->parse( $command->getCommandString() )->getInfo();

				$argv = $syntaxInfo->getLiteralArgv();
				$this->assertNotNull( $argv, 'command.argv' );

				$callback( $argv );

				$result = new UnboxedResult();
				return $result->exitCode( 0 );
			}
		);

		$commandFactory = $this->createMock( CommandFactory::class );
		$commandFactory->expects( $this->once() )->method( 'create' )->willReturn( new Command( $executor ) );
		$this->setService( 'ShellCommandFactory', $commandFactory );
	}
}
