<?php
declare(strict_types=1);

namespace ShlinkioTest\Shlink\Rest\Action\Tag;

use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Shlinkio\Shlink\Core\Entity\Tag;
use Shlinkio\Shlink\Core\Exception\EntityDoesNotExistException;
use Shlinkio\Shlink\Core\Service\Tag\TagServiceInterface;
use Shlinkio\Shlink\Rest\Action\Tag\UpdateTagAction;
use Zend\Diactoros\ServerRequest;

class UpdateTagActionTest extends TestCase
{
    /** @var UpdateTagAction */
    private $action;
    /** @var ObjectProphecy */
    private $tagService;

    public function setUp(): void
    {
        $this->tagService = $this->prophesize(TagServiceInterface::class);
        $this->action = new UpdateTagAction($this->tagService->reveal());
    }

    /**
     * @test
     * @dataProvider provideParams
     */
    public function whenInvalidParamsAreProvidedAnErrorIsReturned(array $bodyParams): void
    {
        $request = (new ServerRequest())->withParsedBody($bodyParams);
        $resp = $this->action->handle($request);

        $this->assertEquals(400, $resp->getStatusCode());
    }

    public function provideParams(): iterable
    {
        yield 'old name only' => [['oldName' => 'foo']];
        yield 'new name only' => [['newName' => 'foo']];
        yield 'no params' => [[]];
    }

    /** @test */
    public function requestingInvalidTagReturnsError(): void
    {
        $request = (new ServerRequest())->withParsedBody([
            'oldName' => 'foo',
            'newName' => 'bar',
        ]);
        $rename = $this->tagService->renameTag('foo', 'bar')->willThrow(EntityDoesNotExistException::class);

        $resp = $this->action->handle($request);

        $this->assertEquals(404, $resp->getStatusCode());
        $rename->shouldHaveBeenCalled();
    }

    /** @test */
    public function correctInvocationRenamesTag(): void
    {
        $request = (new ServerRequest())->withParsedBody([
            'oldName' => 'foo',
            'newName' => 'bar',
        ]);
        $rename = $this->tagService->renameTag('foo', 'bar')->willReturn(new Tag('bar'));

        $resp = $this->action->handle($request);

        $this->assertEquals(204, $resp->getStatusCode());
        $rename->shouldHaveBeenCalled();
    }
}
