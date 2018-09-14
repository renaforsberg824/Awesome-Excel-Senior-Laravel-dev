<?php

namespace Maatwebsite\LaravelNovaExcel\Actions;

use Laravel\Nova\Fields\Field;
use Laravel\Nova\Actions\Action;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Database\Query\Builder;
use Laravel\Nova\Actions\ActionMethod;
use Illuminate\Database\Eloquent\Model;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Laravel\Nova\Http\Requests\ActionRequest;
use Maatwebsite\LaravelNovaExcel\Concerns\Only;
use Maatwebsite\LaravelNovaExcel\Concerns\Except;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\LaravelNovaExcel\Concerns\WithDisk;
use Maatwebsite\LaravelNovaExcel\Concerns\WithFilename;
use Maatwebsite\LaravelNovaExcel\Concerns\WithHeadings;
use Maatwebsite\LaravelNovaExcel\Concerns\WithChunkCount;
use Maatwebsite\LaravelNovaExcel\Concerns\WithWriterType;
use Laravel\Nova\Exceptions\MissingActionHandlerException;
use Maatwebsite\LaravelNovaExcel\Concerns\WithIndexFields;
use Maatwebsite\LaravelNovaExcel\Interactions\AskForFilename;
use Maatwebsite\LaravelNovaExcel\Requests\ExportActionRequest;
use Maatwebsite\LaravelNovaExcel\Interactions\AskForWriterType;
use Maatwebsite\Excel\Concerns\WithHeadings as WithHeadingsConcern;

class ExportToExcel extends Action implements FromQuery, WithCustomChunkSize, WithHeadingsConcern, WithMapping
{
    use AskForFilename,
        AskForWriterType,
        Except,
        Only,
        WithChunkCount,
        WithDisk,
        WithFilename,
        WithHeadings,
        WithIndexFields,
        WithWriterType;

    /**
     * @var Builder
     */
    protected $query;

    /**
     * @var Field[]
     */
    protected $actionFields;

    /**
     * @var callable|null
     */
    protected $onSuccess;

    /**
     * @var callable|null
     */
    protected $onFailure;

    /**
     * Remove all attributes from this class when serializing,
     * so the action can be queued as exportable.
     *
     * @return array
     */
    public function __sleep()
    {
        return ['headings', 'except'];
    }

    /**
     * Execute the action for the given request.
     *
     * @param  \Laravel\Nova\Http\Requests\ActionRequest $request
     *
     * @return mixed
     */
    public function handleRequest(ActionRequest $request)
    {
        $this->handleWriterType($request);
        $this->handleFilename($request);

        $method = ActionMethod::determine($this, $request->targetModel());
        if (!method_exists($this, $method)) {
            throw MissingActionHandlerException::make($this, $method);
        }

        $query = $this->toQuery($request);
        $this->handleHeadings($query);

        return $this->{$method}($request, $this->withQuery($query));
    }

    /**
     * @param ActionRequest $request
     * @param Action        $exportable
     *
     * @return array
     */
    public function handle(ActionRequest $request, Action $exportable): array
    {
        $response = Excel::store(
            $exportable,
            $this->getFilename(),
            $this->getDisk(),
            $this->getWriterType()
        );

        if (false === $response) {
            return \is_callable($this->onFailure)
                ? ($this->onFailure)($request, $response)
                : Action::danger(__('Resource could not be exported.'));
        }

        return \is_callable($this->onSuccess)
            ? ($this->onSuccess)($request, $response)
            : Action::message(__('Resource was successfully exported.'));
    }

    /**
     * @param callable $callback
     *
     * @return $this
     */
    public function onSuccess(callable $callback)
    {
        $this->onSuccess = $callback;

        return $this;
    }

    /**
     * @param callable $callback
     *
     * @return $this
     */
    public function onFailure(callable $callback)
    {
        $this->onFailure = $callback;

        return $this;
    }

    /**
     * @param string $name
     *
     * @return $this
     */
    public function withName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @return Builder
     */
    public function query()
    {
        return $this->query;
    }

    /**
     * @return Field[]
     */
    public function fields()
    {
        return $this->actionFields;
    }

    /**
     * @param Model|mixed $row
     *
     * @return array
     */
    public function map($row): array
    {
        if ($row instanceof Model) {
            return array_except($row->attributesToArray(), $this->getExcept());
        }

        return $row;
    }

    /**
     * @param ActionRequest $request
     *
     * @return \Illuminate\Database\Eloquent\Builder|Builder|mixed
     */
    protected function toQuery(ActionRequest $request)
    {
        return ExportActionRequest
            ::createFromActionRequest($request)
            ->toExportQuery(
                $this->onlyIndexFields,
                $this->getOnly()
            );
    }

    /**
     * @param Builder $query
     *
     * @return $this
     */
    protected function withQuery($query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * @return string
     */
    protected function getDefaultExtension(): string
    {
        return $this->getWriterType() ? strtolower($this->getWriterType()) : 'xlsx';
    }
}
