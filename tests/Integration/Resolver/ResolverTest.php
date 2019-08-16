<?php

namespace Convenia\Pigeon\Tests\Integration\Resolver;

//define('AMQP_DEBUG', true);

use PhpAmqpLib\Message\AMQPMessage;
use Convenia\Pigeon\Resolver\Resolver;
use Convenia\Pigeon\Tests\Integration\TestCase;

class ResolverTest extends TestCase
{
    protected $pigeon;

    protected function setUp()
    {
        parent::setUp();
        $this->pigeon = $this->app['pigeon']->driver('rabbit');
    }

    public function test_it_should_ack_message()
    {
        // setup
        $msg_data = ['foo' => 'fighters', 'bar' => 'baz'];
        $msg = new AMQPMessage(json_encode($msg_data));
        $this->channel->queue_declare($this->queue, $passive = false, $durable = true, $exclusive = false, $auto_delete = false);
        $this->channel->basic_publish($msg, '', $this->queue);

        // act
        $this->channel->basic_consume(
            $this->queue,
            'pigeon.integration.test',
            false,
            false,
            true,
            false,
            function ($request_message) {
                $resolver = new Resolver($request_message);
                $resolver->ack();
            }
        );

        do {
            $this->channel->wait(null, null, 2);
        } while (false);

        // assert
        $msg = $this->channel->basic_get($this->queue);
        $this->assertNull($msg);
    }

    public function test_it_should_reject_message_and_requeue()
    {
        // setup
        $msg_data = ['foo' => 'fighters', 'bar' => 'baz'];
        $msg = new AMQPMessage(json_encode($msg_data));
        $this->channel->queue_declare($this->queue, $passive = false, $durable = true, $exclusive = false, $auto_delete = false);
        $this->channel->basic_publish($msg, '', $this->queue);

        // act
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume(
            $this->queue,
            'pigeon.integration.test',
            false,
            false,
            false,
            false,
            function ($m) {
                $resolver = new Resolver($m);
                $resolver->reject();
            }
        );

        $this->channel->wait();
        // assert
        $msg = $this->channel->basic_get($this->queue, true);
        $this->assertNotNull($msg);
        $this->assertEquals($msg_data, json_decode($msg->body, true));
    }

    public function test_it_should_publish_a_message_reponse()
    {
        // setup
        $msg_data = ['foo' => 'fighters'];
        $response_data = ['bar' => 'baz'];
        [$reply_to, ] = $this->channel->queue_declare();
        $msg = new AMQPMessage(json_encode($msg_data), ['reply_to' => $reply_to]);
        $this->channel->queue_declare($this->queue, $passive = false, $durable = true, $exclusive = false, $auto_delete = false);
        $this->channel->basic_publish($msg, '', $this->queue);

        // act
        $this->channel->basic_qos(null, 1, null);
        $this->channel->basic_consume(
            $this->queue,
            'pigeon.integration.test',
            false,
            false,
            true,
            false,
            function ($request_message) use ($response_data) {
                $resolver = new Resolver($request_message);
                $resolver->response($response_data);
            }
        );

        $this->channel->wait(null, false, 2);
        sleep(2);

        // assert
        $response_msg = $this->channel->basic_get($reply_to);
        $this->assertNotNull($response_msg);
        $this->assertEquals($response_data, json_decode($response_msg->body, true));

        $this->channel->queue_delete($reply_to);
    }

    protected function tearDown()
    {
        $this->pigeon->getConnection()->close();
        parent::tearDown();
    }
}
