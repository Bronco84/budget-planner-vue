<?php

use App\Models\File;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Panel;
use Laravel\Nova\Resource;
use Symfony\Component\Finder\Finder;

/*
 * PR-090: a Nova relationship field naming a relation the model does not define
 * (the File resource named 'uploader' for File::uploadedBy) makes the resource's
 * detail page fail with a 500. Every relationship field of every resource must
 * name a method on its model that returns an Eloquent relation.
 */

function novaRelationAdmin(): User
{
    $admin = User::factory()->create();
    // is_admin is deliberately not mass-assignable.
    $admin->is_admin = true;
    $admin->save();

    return $admin;
}

/**
 * @return list<class-string<resource>>
 */
function novaResourceClasses(): array
{
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Nova'))->depth(0)->name('*.php') as $file) {
        $class = 'App\\Nova\\'.$file->getBasename('.php');

        if (is_subclass_of($class, Resource::class) && ! (new ReflectionClass($class))->isAbstract()) {
            $classes[] = $class;
        }
    }

    return $classes;
}

/**
 * @return list<Field>
 */
function novaFlattenFields(iterable $fields): array
{
    $flat = [];

    foreach ($fields as $field) {
        if ($field instanceof Panel) {
            array_push($flat, ...novaFlattenFields($field->data));
        } elseif ($field instanceof Field) {
            $flat[] = $field;
        }
    }

    return $flat;
}

test('every nova relationship field names a relation its model defines', function () {
    $this->actingAs(novaRelationAdmin());
    $request = NovaRequest::create('/nova-api/resources');
    $request->setUserResolver(fn () => auth()->user());

    $missing = [];
    $checked = 0;

    foreach (novaResourceClasses() as $class) {
        $model = new ($class::$model);
        $resource = new $class($model);

        foreach (novaFlattenFields($resource->fields($request)) as $field) {
            $isRelation = $field instanceof MorphTo
                || property_exists($field, 'resourceClass')
                || property_exists($field, 'resourceName');

            if (! $isRelation) {
                continue;
            }

            $checked++;
            $relation = $field->attribute;

            if (! method_exists($model, $relation) || ! ($model->{$relation}() instanceof Relation)) {
                $missing[] = $class.' field "'.$field->name.'" names '.$model::class.'::'.$relation.'()';
            }
        }
    }

    expect($checked)->toBeGreaterThan(0)
        ->and($missing)->toBe([]);
});

test('an admin can open a file detail page in nova', function () {
    Storage::fake('s3');
    $admin = novaRelationAdmin();
    $file = File::create([
        'hash' => 'hash-detail',
        'original_name' => 'receipt.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 12,
        'extension' => 'pdf',
        'uploaded_by' => $admin->id,
    ]);

    $this->actingAs($admin)
        ->getJson('/nova-api/files/'.$file->id)
        ->assertOk()
        ->assertJsonPath('resource.id.value', $file->id);
});
