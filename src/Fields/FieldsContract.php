<?php

namespace LaraZeus\Bolt\Fields;

use Filament\Forms\Get;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use LaraZeus\Bolt\BoltPlugin;
use LaraZeus\Bolt\Concerns\HasOptions;
use LaraZeus\Bolt\Contracts\Fields;
use LaraZeus\Bolt\Facades\Bolt;
use LaraZeus\Bolt\Models\Field;
use LaraZeus\Bolt\Models\FieldResponse;
use LaraZeus\BoltPreset\Models\Field as FieldPreset;

/** @phpstan-return Arrayable<string,mixed> */
abstract class FieldsContract implements Arrayable, Fields
{
    use HasOptions;

    public bool $disabled = false;

    public string $renderClass;

    public int $sort;

    public function toArray(): array
    {
        return [
            'disabled' => $this->disabled,
            'class' => '\\' . get_called_class(),
            'renderClass' => $this->renderClass,
            'hasOptions' => $this->hasOptions(),
            'code' => class_basename($this),
            'sort' => $this->sort,
            'title' => $this->title(),
        ];
    }

    public function title(): string
    {
        return __(class_basename($this));
    }

    public function hasOptions(): bool
    {
        return method_exists(get_called_class(), 'getOptions');
    }

    public function getResponse(Field $field, FieldResponse $resp): string
    {
        return $resp->response;
    }

    // @phpstan-ignore-next-line
    public function appendFilamentComponentsOptions($component, $zeusField)
    {
        if (is_string($zeusField->options)) {
            $zeusField->options = json_decode($zeusField->options, true);
        }

        $htmlId = $zeusField->options['htmlId'] ?? str()->random(6);

        $component
            ->label($zeusField->name)
            ->id($htmlId)
            ->helperText($zeusField->description);

        if (isset($zeusField->options['prefix']) && $zeusField->options['prefix'] !== null) {
            $component = $component->prefix($zeusField->options['prefix']);
        }

        if (isset($zeusField->options['suffix']) && $zeusField->options['suffix'] !== null) {
            $component = $component->suffix($zeusField->options['suffix']);
        }

        if (optional($zeusField->options)['is_required']) {
            $component = $component->required();
        }

        if (request()->filled($htmlId)) {
            $component = $component->default(request($htmlId));
        }

        if (optional($zeusField->options)['column_span_full']) {
            $component = $component->columnSpanFull();
        }

        if (optional($zeusField->options)['hint']) {
            if (optional($zeusField->options)['hint']['text']) {
                $component = $component->hint($zeusField->options['hint']['text']);
            }
            if (optional($zeusField->options)['hint']['icon']) {
                $component = $component->hintIcon($zeusField->options['hint']['icon'], tooltip: $zeusField->options['hint']['text']);
            }
            if (optional($zeusField->options)['hint']['color']) {
                $component = $component->hintColor(fn () => Color::hex($zeusField->options['hint']['color']));
            }
        }

        $component = $component
            ->visible(function ($record, Get $get) use ($zeusField) {
                if (! isset($zeusField->options['visibility']) || ! $zeusField->options['visibility']['active']) {
                    return true;
                }

                $relatedField = $zeusField->options['visibility']['fieldID'];
                $relatedFieldValues = $zeusField->options['visibility']['values'];

                if (empty($relatedField) || empty($relatedFieldValues)) {
                    return true;
                }

                if (is_array($get('zeusData.' . $relatedField))) {
                    return in_array($relatedFieldValues, $get('zeusData.' . $relatedField));
                }

                return $relatedFieldValues === $get('zeusData.' . $relatedField);
            });

        return $component->live(onBlur: true);
    }

    public function getCollectionsValuesForResponse(Field $field, FieldResponse $resp): string
    {
        $response = $resp->response;

        if (blank($response)) {
            return '';
        }

        if (Bolt::isJson($response)) {
            $response = json_decode($response);
        }

        $response = Arr::wrap($response);

        // to not braking old dataSource structure
        if ((int) $field->options['dataSource'] !== 0) {
            $response = BoltPlugin::getModel('Collection')::query()
                ->find($field->options['dataSource'])
                ->values
                ->whereIn('itemKey', $response)
                ->pluck('itemValue')
                ->join(', ');
        } else {
            $dataSourceClass = new $field->options['dataSource'];
            $response = $dataSourceClass->getModel()::query()
                ->whereIn($dataSourceClass->getKeysUsing(), $response)
                ->pluck($dataSourceClass->getValuesUsing())
                ->join(', ');
        }

        return (is_array($response)) ? implode(', ', $response) : $response;
    }

    //@phpstan-ignore-next-line
    public static function getFieldCollectionItemsList(Field | FieldPreset $zeusField): Collection
    {
        $getCollection = collect();

        //@phpstan-ignore-next-line
        if ($zeusField instanceof FieldPreset && is_string($zeusField->options)) {
            //@phpstan-ignore-next-line
            $zeusField->options = json_decode($zeusField->options, true);
        }

        // to not braking old dataSource structure
        //@phpstan-ignore-next-line
        if ((int) $zeusField->options['dataSource'] !== 0) {
            //@phpstan-ignore-next-line
            if ($zeusField instanceof FieldPreset) {
                //@phpstan-ignore-next-line
                $getCollection = \LaraZeus\BoltPreset\Models\Collection::query()
                    //@phpstan-ignore-next-line
                    ->find($zeusField->options['dataSource'] ?? 0)
                    ->values;
                //@phpstan-ignore-next-line
                $getCollection = collect(json_decode($getCollection, true))
                    ->pluck('itemValue', 'itemKey');
            } else {
                $getCollection = BoltPlugin::getModel('Collection')::query()
                    ->find($zeusField->options['dataSource'] ?? 0);
                if ($getCollection === null) {
                    $getCollection = collect();
                } else {
                    $getCollection = $getCollection->values->pluck('itemValue', 'itemKey');
                }
            }
        } else {
            if (class_exists($zeusField->options['dataSource'])) {
                //@phpstan-ignore-next-line
                $dataSourceClass = new $zeusField->options['dataSource'];
                $getCollection = $dataSourceClass->getModel()::pluck(
                    $dataSourceClass->getValuesUsing(),
                    $dataSourceClass->getKeysUsing()
                );
            }
        }

        return $getCollection;
    }
}
