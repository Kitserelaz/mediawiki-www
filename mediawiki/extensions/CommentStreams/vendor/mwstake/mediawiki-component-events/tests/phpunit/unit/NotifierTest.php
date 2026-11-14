<?php

namespace MWStake\MediaWiki\Component\Events\Tests\Unit;

use MediaWiki\HookContainer\HookContainer;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use MWStake\MediaWiki\Component\Events\Notifier;
use Wikimedia\Rdbms\LBFactory;

class NotifierTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideOverrideData
	 * @covers \MWStake\MediaWiki\Component\Events\Notifier::emit
	 */
	public function testOverrides( $main, $sub, $expectedCalls ) {
		$consumer = $this->getMockBuilder( DummyConsumer::class )
			->onlyMethods( [ 'consume', 'isInterested' ] )
			->getMock();
		$consumer->expects( $this->exactly( $expectedCalls ) )
			->method( 'consume' );
		$consumer->expects( $this->exactly( $expectedCalls ) )
			->method( 'isInterested' )
			->willReturn( true );

		$notifier = new Notifier(
			[ $consumer ],
			$this->getLBFactoryMock(),
			$this->getHookContainerMock()
		);
		$notifier->emit( $main );
		$notifier->emit( $sub );
		$notifier->flush();
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\Events\Notifier::emit
	 */
	public function testEmit() {
		$event = new DummyEvent( $this->getUserMock() );

		$consumer = $this->getMockBuilder( DummyConsumer::class )
			->onlyMethods( [ 'consume', 'isInterested' ] )
			->getMock();
		$consumer->expects( $this->once() )
			->method( 'consume' )
			->with( $event );
		$consumer->expects( $this->once() )
			->method( 'isInterested' )
			->with( $event )
			->willReturn( true );

		$notifier = new Notifier(
			[ $consumer ],
			$this->getLBFactoryMock(),
			$this->getHookContainerMock()
		);
		$notifier->emit( $event );
		$notifier->flush();
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\Events\Notifier::emit
	 */
	public function testEmitNotInterested() {
		$event = new DummyEvent( $this->getUserMock() );

		$consumer = $this->getMockBuilder( DummyConsumer::class )
			->onlyMethods( [ 'consume', 'isInterested' ] )
			->getMock();
		$consumer->expects( $this->never() )
			->method( 'consume' );
		$consumer->expects( $this->once() )
			->method( 'isInterested' )
			->with( $event )
			->willReturn( false );

		$notifier = new Notifier(
			[ $consumer ],
			$this->getLBFactoryMock(),
			$this->getHookContainerMock()
		);
		$notifier->emit( $event );
		$notifier->flush();
	}

	/**
	 * @covers \MWStake\MediaWiki\Component\Events\Notifier::emit
	 */
	public function testEmitMultipleConsumers() {
		$event = new DummyEvent( $this->getUserMock() );

		$consumer1 = $this->getMockBuilder( DummyConsumer::class )
			->onlyMethods( [ 'consume', 'isInterested' ] )->getMock();
		$consumer1->expects( $this->once() )->method( 'consume' )->with( $event );
		$consumer1->expects( $this->once() )
			->method( 'isInterested' )
			->with( $event
			)->willReturn( true );

		$consumer2 = $this->getMockBuilder( DummyConsumer::class )
			->onlyMethods( [ 'consume', 'isInterested' ] )->getMock();
		$consumer2->expects( $this->once() )->method( 'consume' )->with( $event );
		$consumer2->expects( $this->once() )
			->method( 'isInterested' )
			->with( $event )
			->willReturn( true );

		$notifier = new Notifier(
			[ $consumer1, $consumer2 ],
			$this->getLBFactoryMock(),
			$this->getHookContainerMock()
		);
		$notifier->emit( $event );
		$notifier->flush();
	}

	/**
	 * @return LBFactory&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getLBFactoryMock() {
		$mock = $this->getMockBuilder( LBFactory::class )
			->disableOriginalConstructor()
			->getMock();

		return $mock;
	}

	/**
	 * @return HookContainer&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getHookContainerMock() {
		$mock = $this->getMockBuilder( HookContainer::class )
			->disableOriginalConstructor()
			->getMock();

		return $mock;
	}

	/**
	 * @return User&\PHPUnit\Framework\MockObject\MockObject
	 */
	public function getUserMock( ?int $id = 1 ) {
		$mock = $this->getMockBuilder( User::class )
			->disableOriginalConstructor()
			->getMock();
		$mock->method( 'getId' )->willReturn( $id );

		return $mock;
	}

	/**
	 * @return array[]
	 */
	public function provideOverrideData() {
		return [
			'different-events-same-topic' => [
				new DummyEvent( $this->getUserMock() ),
				new DummySubEvent( $this->getUserMock() ),
				1
			],
			'opposite-order' => [
				new DummySubEvent( $this->getUserMock() ),
				new DummyEvent( $this->getUserMock() ),
				1
			],
			'different-agent' => [
				new DummyEvent( $this->getUserMock() ),
				new DummySubEvent( $this->getUserMock( 2 ), 2 ),
				2
			]
		];
	}

}
