<?php
/*************************************************************************
 * Dataphyre
 *
 * Copyright (c) 2026 Shopiro Ltd.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

use Dataphyre\Panel\PanelMediaCollection;
use Dataphyre\Panel\PanelMediaItem;
use Dataphyre\Test\Context;
use function Dataphyre\Test\framework;
use function Dataphyre\Test\test;

framework(['panel']);

test('panel media collections cover definitions metadata validation rules and upload diagnostics',static function(Context $t): void {
	$t->same('press_photos',PanelMediaCollection::from('Press Photos')->name());
	$collection=PanelMediaCollection::from([
		'name'=>'Product Photos',
		'label'=>' Product imagery ',
		'disk'=>'Public Assets',
		'path'=>' /catalog/{collection}/ ',
		'visibility'=>'protected',
		'multiple'=>true,
		'accepted_types'=>' image/*, .pdf ',
		'min_size'=>1048576,
		'max_size'=>512,
		'variants'=>['Thumb Size'=>['width'=>96],'invalid'=>'ignored'],
		'cleanup'=>['orphans'=>true],
		'metadata'=>['source'=>'definition'],
	]);
	$collection->meta(['owner'=>'catalog'])->meta('region','north')->previewTypes('image/jpeg, application/pdf');
	$array=$collection->toArray();
	$t->same('product_photos',$array['name']);
	$t->same('Product imagery',$array['label']);
	$t->same('public_assets',$array['disk']);
	$t->same('catalog/product_photos',$array['resolved_path']);
	$t->same('protected',$array['visibility']);
	$t->same(true,$array['multiple']);
	$t->same(['image/*','.pdf'],$array['accepted_types']);
	$t->same('north',$array['metadata']['region']);
	$t->same($array,$collection->manifest());
	$t->same($array,$collection->jsonSerialize());

	$collection->validateUsing(static function(array $file, PanelMediaCollection $collection, array $errors, array $warnings): array {
		return [
			'errors'=>['custom '.$collection->name().' '.count($errors)],
			'warnings'=>['custom '.count($warnings).' '.($file['name'] ?? '')],
		];
	});
	$result=$collection->validate(['name'=>'','type'=>'text/plain','size'=>700,'error'=>UPLOAD_ERR_PARTIAL]);
	$t->same(false,$result['ok']);
	$t->same(5,count($result['errors']));
	$t->same(2,count($result['warnings']));
	$t->same('custom product_photos 4',$result['errors'][4]);

	$stringValidation=PanelMediaCollection::make('documents')->validateUsing(static fn(): string=>'custom string error');
	$t->same(['custom string error'],$stringValidation->validate(['name'=>'doc.txt'])['errors']);
	$t->same('documents',$stringValidation->item(['name'=>'doc.txt'],['filename'=>'stored.txt'])->collection());

	$file=['name'=>'Photo.JPG','type'=>'image/jpeg'];
	$t->same(false,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['']));
	$t->same(true,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['*']));
	$t->same(true,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['*/*']));
	$t->same(true,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['.png','.jpg']));
	$t->same(true,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['text/*','image/*']));
	$t->same(true,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['application/pdf','image/jpeg']));
	$t->same(true,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['png','jpg']));
	$t->same(false,$t->nonPublic(PanelMediaCollection::class)->invoke('fileAccepted',$file,['.png','text/*','application/pdf','png']));
	$t->same('1 MB',$t->nonPublic(PanelMediaCollection::class)->invoke('formatBytes',1048576));
	$t->same('512 B',$t->nonPublic(PanelMediaCollection::class)->invoke('formatBytes',512));

	$uploadErrors=[
		UPLOAD_ERR_INI_SIZE=>'file is too large',
		UPLOAD_ERR_FORM_SIZE=>'file is too large',
		UPLOAD_ERR_PARTIAL=>'upload was incomplete',
		UPLOAD_ERR_NO_FILE=>'no file was selected',
		UPLOAD_ERR_NO_TMP_DIR=>'temporary directory is missing',
		UPLOAD_ERR_CANT_WRITE=>'server could not write the file',
		UPLOAD_ERR_EXTENSION=>'a PHP extension stopped the upload',
		999=>'unknown upload error',
	];
	foreach($uploadErrors as $code=>$message){
		$t->same($message,$t->nonPublic(PanelMediaCollection::class)->invoke('uploadError',$code));
	}
})->tag('panel','media','coverage')->group('framework-coverage');

test('panel media items cover collection sources accessors MIME guesses and preview fallbacks',static function(Context $t): void {
	$item=new PanelMediaItem([
		'id'=>'media-1',
		'collection'=>'Product Photos',
		'disk'=>'Public Assets',
		'visibility'=>'Protected',
		'original_name'=>"../unsafe folder/Photo Final.PDF",
		'extension'=>'PDF',
		'filename'=>'stored-photo.pdf',
		'mime'=>' APPLICATION/PDF ',
		'size'=>-10,
		'path'=>'/catalog/stored-photo.pdf/',
		'url'=>' https://example.test/photo.pdf ',
		'variants'=>['thumb'=>['url'=>'thumb.jpg']],
		'metadata'=>['alt'=>'Photo'],
		'validation'=>['ok'=>false,'errors'=>['blocked'],'warnings'=>[]],
	]);
	$t->same('media-1',$item->id());
	$t->same('product_photos',$item->collection());
	$t->same('public_assets',$item->disk());
	$t->same('catalog/stored-photo.pdf',$item->path());
	$t->same('stored-photo.pdf',$item->filename());
	$t->same('unsafe-folder-Photo-Final.PDF',$item->originalName());
	$t->same('application/pdf',$item->mime());
	$t->same('pdf',$item->extension());
	$t->same(0,$item->size());
	$t->same('protected',$item->visibility());
	$t->same('https://example.test/photo.pdf',$item->url());
	$t->same(['thumb'=>['url'=>'thumb.jpg']],$item->variants());
	$t->same(['alt'=>'Photo'],$item->metadata());
	$t->same(false,$item->valid());
	$t->same(['blocked'],$item->validation()['errors']);
	$t->same(true,$item->previewable());
	$t->same($item->toArray(),$item->jsonSerialize());

	$fromArray=PanelMediaItem::from(['filename'=>'array.csv','mime'=>'text/csv','size'=>12],[
		'collection'=>'exports','disk'=>'archive','filename'=>'stored.csv',
	]);
	$t->same('exports',$fromArray->collection());
	$t->same('archive',$fromArray->disk());
	$t->same('array.csv',$fromArray->originalName());
	$fromString=PanelMediaItem::from(['name'=>'string.txt'],'Text Files',['filename'=>'stored.txt']);
	$t->same('text_files',$fromString->collection());
	$fromBlank=PanelMediaItem::from(['name'=>'blank.txt'],'   ',['filename'=>'stored.txt']);
	$t->same('default',$fromBlank->collection());
	$fromCollection=PanelMediaCollection::make('avatars')->public()->accept('image/*')->item(
		['name'=>'avatar.png','type'=>'image/png','size'=>200],
		['filename'=>'avatar-stored.png']
	);
	$t->same('avatars',$fromCollection->collection());
	$t->same('public',$fromCollection->visibility());
	$t->same(true,$fromCollection->valid());

	$mimeByExtension=[
		'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
		'svg'=>'image/svg+xml','pdf'=>'application/pdf','csv'=>'text/csv','json'=>'application/json',
		'txt'=>'text/plain','mp4'=>'video/mp4','mp3'=>'audio/mpeg','bin'=>'application/octet-stream',
	];
	foreach($mimeByExtension as $extension=>$mime){
		$guessed=new PanelMediaItem(['name'=>'source.'.$extension,'filename'=>'stored.'.$extension]);
		$t->same($mime,$guessed->mime());
	}
	$t->same(true,(new PanelMediaItem(['name'=>'movie.bin','filename'=>'movie.bin','mime'=>'video/custom']))->previewable());
	$t->same(true,(new PanelMediaItem(['name'=>'sound.bin','filename'=>'sound.bin','mime'=>'audio/custom']))->previewable());
	$t->same(false,(new PanelMediaItem(['name'=>'archive.zip','filename'=>'archive.zip','mime'=>'application/zip']))->previewable());
})->tag('panel','media','coverage')->group('framework-coverage');
