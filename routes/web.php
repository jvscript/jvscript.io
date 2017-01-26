<?php

/*
  |--------------------------------------------------------------------------
  | Web Routes
  |--------------------------------------------------------------------------
  |
  | Here is where you can register web routes for your application. These
  | routes are loaded by the RouteServiceProvider within a group which
  | contains the "web" middleware group. Now create something great!
  |
 */

Route::get('/', 'JvscriptController@index')->name('index');

//forms
Route::get('/script/ajout', 'JvscriptController@formScript')->name('script.form');
Route::get('/skin/ajout', 'JvscriptController@formSkin')->name('skin.form');

//form action (store in db)
Route::post('/script/ajout', 'JvscriptController@storeScript')->name('script.store');
Route::post('/skin/ajout', 'JvscriptController@storeSkin')->name('skin.store');


Route::get('/script/{slug}', 'JvscriptController@showScript')->name('script.show');
Route::get('/script/install/{slug}', 'JvscriptController@installScript')->name('script.install');
Route::get('/script/note/{slug}/{note}', 'JvscriptController@noteScript')->name('script.note');

Route::get('/skin/{slug}', 'JvscriptController@showSkin')->name('skin.show');
Route::get('/skin/install/{slug}', 'JvscriptController@installSkin')->name('skin.install');
Route::get('/skin/note/{slug}/{note}', 'JvscriptController@noteSkin')->name('skin.note');


//updates
Route::get('/script/{slug}/edit', 'JvscriptController@editScript')->name('script.edit');
Route::get('/skin/{slug}/edit', 'JvscriptController@editSkin')->name('skin.edit');
Route::put('/script/{slug}/edit', 'JvscriptController@updateScript')->name('script.update');
Route::put('/skin/{slug}/edit', 'JvscriptController@updateSkin')->name('skin.update');

//contact form
Route::get('/contact/{message_body?}', function ($message_body = null) {
    return view('contact', ['message_body' => $message_body]);
})->name('contact.form');
//contact action
Route::post('/contact', 'JvscriptController@contactSend')->name('contact.send');


//static views 
Route::get('/developpeurs', function () {
    return view('developpeurs');
});
Route::get('/aide', function () {
    return view('comment-installer');
})->name('aide');


Auth::routes();
