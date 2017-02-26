<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Script,
    App\Skin,
    App\User,
    App\Comment,
    App\History;
use Validator;
use Auth;
use App;
use App\Notifications\notifyStatus;
use App\Lib\Lib;
use Image;

class JvscriptController extends Controller {

    //_TODO : retenir le filtre/sort en session/cookie utilisateur 
    /**
     * Create a new controller instance.     *
     * @return void
     */
    public function __construct() {
        if (App::environment('local', 'testing')) {
            $this->recaptcha_key = '6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe';
        } else { //prod
            $this->recaptcha_key = env('RECAPTCHA_KEY', '');
        }

        $this->discord_url = env('DISCORD_URL', '');
        $this->min_time_comment = 30; //limite de temps entre chaque commentaire
        $this->min_time_captcha = 60; //limite de temps entre chaque commentaire pour faire disparaitre le captcha
        $this->lib = new Lib();
    }

    /**
     * Delete comment
     */
    public function deleteComment($slug, $comment_id, Request $request) {
        $user = Auth::user();
        $route = \Request::route()->getName();
        if (str_contains($route, "script")) {
            $item = 'script';
            $model = Script::where('slug', $slug)->firstOrFail();
        } else if (str_contains($route, "skin")) {
            $item = 'skin';
            $model = Skin::where('slug', $slug)->firstOrFail();
        }
        $comment = Comment::findOrFail($comment_id);
        $this->lib->ownerOradminOrFail($comment->user_id);
        $comment->delete();
        return redirect(route("$item.show", $slug) . "#comments");
    }

    /**
     * Renvoie true si l'user doit être limité
     * @param int $seconds
     * @return true if limited comment
     */
    public function limitComment($seconds) {
        $user = Auth::user();
        if (!$user)
            return false;
        return $user->comments()->where('created_at', '>', \Carbon\Carbon::now()->subSeconds($seconds))->count();
    }

    /**
     * Store comment
     */
    public function storeComment($slug, Request $request) {
        $user = Auth::user();
        $route = \Request::route()->getName();
        if (str_contains($route, "script")) {
            $item = 'script';
            $model = Script::where('slug', $slug)->firstOrFail();
        } else if (str_contains($route, "skin")) {
            $item = 'skin';
            $model = Skin::where('slug', $slug)->firstOrFail();
        }

        $validator = Validator::make($request->all(), ['comment' => "required|max:255"]);

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else {
            //captcha validation
            $recaptcha = new \ReCaptcha\ReCaptcha($this->recaptcha_key);
            $resp = $recaptcha->verify($request->input('g-recaptcha-response'), $request->ip());
            //Anti spam 30 secondes
            if ($this->limitComment($this->min_time_comment)) {
                $request->flash();
                return redirect(route("$item.show", $slug) . "#comments")->withErrors(['comment' => "Veuillez attendre $this->min_time_comment secondes entre chaque commentaire svp."]);
            }
            //anti spam 60 secondes : besoin validation captcha
            if ($this->limitComment($this->min_time_captcha)) {
                if ((!App::environment('testing', 'local') && !$resp->isSuccess())) {
                    $request->flash();
                    return redirect(route("$item.show", $slug) . "#comments")->withErrors(['recaptcha' => 'Veuillez valider le captcha svp.']);
                }
            }
            $comment = $request->input('comment');
            $model->comments()->create(['comment' => $comment, 'user_id' => $user->id]);
            //_TODO : notify autor 
            return redirect(route("$item.show", $slug) . "#comments");
        }
    }

    function isImage($path) {
        $a = getimagesize($path);
        $image_type = $a[2];

        if (in_array($image_type, array(IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP))) {
            return true;
        }
        return false;
    }

    public function storeImage($item, $file, $filename) {
        $filename = strtolower(preg_replace('/[^a-zA-Z0-9-_\.]/', '-', $filename));
        $filename = $item->id . '-' . $filename;

        $img = Image::make($file);

        if ($img->mime() == 'image/png') {
            //_TODO COMPRESS PNG pour garder la transparence
        } else {
            $img->encode('jpg');
        }

        //== RESIZE NORMAL ==
        $img->resize(1000, null, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $img->resize(null, 1000, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        \File::exists(storage_path('app/public/images/')) or \File::makeDirectory(storage_path('app/public/images/'));
        $img->save('storage/images/' . $filename, 90);

        //== RESIZE MINIATURE ==
        $img->resize(261, null, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $img->resize(null, 261, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });
        $img->save('storage/images/small-' . $filename, 85);

        //store photo in DB
        $item->photo_url = '/storage/images/' . $filename;
        $item->save();
    }

    /**
     * Store a script in db
     */
    public function storeScript(Request $request) {
        $user = Auth::user();
        $messages = [
            'js_url.regex' => 'Le lien du script doit terminer par \'.js\'',
        ];
        $validator = Validator::make($request->all(), [
                    'name' => 'required|max:50|unique:scripts|not_in:ajout',
                    'description' => 'required',
                    "autor" => "max:255",
                    'js_url' => "required|url|max:255|regex:/.*\.js$/",
                    'repo_url' => "url|max:255",
                    'photo_url' => "url|max:255",
                    'photo_file' => "image",
                    'don_url' => "url|max:255",
                    'website_url' => "url|max:255",
                    'topic_url' => "url|max:255|regex:/^https?:\/\/www\.jeuxvideo\.com\/forums\/.*/",
                        ], $messages);

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else { //sucess > insert  
            //captcha validation
            $recaptcha = new \ReCaptcha\ReCaptcha($this->recaptcha_key);
            $resp = $recaptcha->verify($request->input('g-recaptcha-response'), $request->ip());
            if (!App::environment('testing', 'local') && !$resp->isSuccess()) {
                $request->flash();
                return redirect(route('script.form'))->withErrors(['recaptcha' => 'Veuillez valider le captcha svp.']);
            }

            $script = Script::create($request->all());
            $script->slug = $this->slugifyScript($script->name);

            if ($request->input("is_autor") == 'on') {
                $script->user_id = $user->id; //owner du script               
                $script->autor = $user->name;
            }
            $script->poster_user_id = $user->id;

            //store photo_file or photo_url  storage
            if ($request->file('photo_file')) {
                $filename = $request->file('photo_file')->getClientOriginalName();
                $this->storeImage($script, $request->file('photo_file'), $filename);
            } else if ($request->has('photo_url')) {
                if ($this->isImage($request->input('photo_url'))) {
                    $file = @file_get_contents($request->input('photo_url'));
                    $filename = basename($request->input('photo_url'));
                    $this->storeImage($script, $file, $filename);
                } else {
                    $script->photo_url = null;
                }
            }

            $script->save();

            $message = "[new script] Nouveau script posté sur le site : " . route('script.show', ['slug' => $script->slug]);
            $this->lib->sendDiscord($message, $this->discord_url);

            return redirect(route('script.form'))->with("message", "Merci, votre script est en attente de validation.");
        }
    }

    /**
     * Store a skin in db
     */
    public function storeSkin(Request $request) {
        $user = Auth::user();
        $messages = [
            'skin_url.regex' => 'Le champ :attribute doit être un lien du format \'https://userstyles.org/styles/...\'',
        ];
        $validator = Validator::make($request->all(), [
                    'name' => 'required|max:50|unique:skins|not_in:ajout',
                    'description' => 'required',
                    "autor" => "max:255",
                    'skin_url' => "required|url|max:255|regex:/^https:\/\/userstyles\.org\/styles\/.*/",
                    'repo_url' => "url|max:255",
                    'photo_url' => "url|max:255",
                    'photo_file' => "image",
                    'don_url' => "url|max:255",
                    'website_url' => "url|max:255",
                    'topic_url' => "url|max:255|regex:/^https?:\/\/www\.jeuxvideo\.com\/forums\/.*/",
                        ], $messages);

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else { //sucess > insert  
            //captcha validation
            $recaptcha = new \ReCaptcha\ReCaptcha($this->recaptcha_key);
            $resp = $recaptcha->verify($request->input('g-recaptcha-response'), $request->ip());
            if (!App::environment('testing') && !$resp->isSuccess()) {
                $request->flash();
                return redirect(route('skin.form'))->withErrors(['recaptcha' => 'Veuillez valider le captcha svp.']);
            }

            $script = Skin::create($request->all());
            $script->slug = $this->slugifySkin($script->name);

            if ($request->input("is_autor") == 'on') {
                $script->user_id = $user->id; //owner script
                $script->autor = $user->name;
            }
            $script->poster_user_id = $user->id;

            //_TODO : supprimer ancienne image si existe
            //store photo_file or photo_url  storage
            if ($request->file('photo_file')) {
                $filename = $request->file('photo_file')->getClientOriginalName();
                $this->storeImage($script, $request->file('photo_file'), $filename);
            } else if ($request->has('photo_url')) {
                if ($this->isImage($request->input('photo_url'))) {
                    $file = @file_get_contents($request->input('photo_url'));
                    $filename = basename($request->input('photo_url'));
                    $this->storeImage($script, $file, $filename);
                } else {
                    $script->photo_url = null;
                }
            }
            $script->save();

            $message = "[new skin] Nouveau skin posté sur le site : " . route('skin.show', ['slug' => $script->slug]);
            $this->lib->sendDiscord($message, $this->discord_url);

            return redirect(route('skin.form'))->with("message", "Merci, votre skin est en attente de validation.");
        }
    }

    /**
     * admin or owner
     */
    public function updateScript(Request $request, $slug) {
        $script = Script::where('slug', $slug)->firstOrFail();
        $this->lib->ownerOradminOrFail($script->user_id);

        $messages = [
            'js_url.regex' => 'Le lien du script doit terminer par \'.js\'',
        ];
        $validator = Validator::make($request->all(), [
                    "autor" => "max:255",
                    'js_url' => "required|url|max:255|regex:/.*\.js$/",
                    'repo_url' => "url|max:255",
                    'photo_url' => "url|max:255",
                    'don_url' => "url|max:255",
                    'user_id' => "exists:users,id",
                    'sensibility' => "in:0,1,2",
                    'last_update' => "date_format:d/m/Y",
                    'website_url' => "url|max:255",
                    'topic_url' => "url|max:255|regex:/^https?:\/\/www\.jeuxvideo\.com\/forums\/.*/",
                        ], $messages);

        //update only this fields
        $toUpdate = ['sensibility', 'autor', 'description', 'js_url', 'repo_url', 'photo_url', 'don_url', 'website_url', 'topic_url', 'version', 'last_update'];
        if (Auth::user()->isAdmin()) {
            $toUpdate = ['sensibility', 'autor', 'description', 'js_url', 'repo_url', 'photo_url', 'don_url', 'website_url', 'topic_url', 'user_id', 'version', 'last_update'];
            if ($request->input('user_id') == '') {
                $request->merge(['user_id' => null]);
            } else {
                //force username of owner 
                $request->merge(['autor' => User::find($request->input('user_id'))->name]);
            }
        }

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else {
            $script->fill($request->only($toUpdate));
            $script->version = $request->input('version');
            if ($request->has('last_update')) {
                $script->last_update = \Carbon\Carbon::createFromFormat('d/m/Y', $request->input('last_update'));
            }
            $script->save();
            return redirect(route('script.show', ['slug' => $slug]));
        }
    }

    public function updateSkin(Request $request, $slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();
        $this->lib->ownerOradminOrFail($skin->user_id);

        $messages = [
            'skin_url.regex' => 'Le champ :attribute doit être un lien du format \'https://userstyles.org/styles/...\'',
        ];
        $validator = Validator::make($request->all(), [
                    'skin_url' => "required|url|max:255|regex:/^https:\/\/userstyles\.org\/styles\/.*/",
                    'repo_url' => "url|max:255",
                    'photo_url' => "url|max:255",
                    'user_id' => "exists:users,id",
                    'don_url' => "url|max:255",
                    'last_update' => "date_format:d/m/Y",
                    'website_url' => "url|max:255",
                    'topic_url' => "url|max:255|regex:/^https?:\/\/www\.jeuxvideo\.com\/forums\/.*/",
                        ], $messages);
        //update only this fields
        $toUpdate = ['sensibility', 'autor', 'description', 'js_url', 'repo_url', 'photo_url', 'don_url', 'website_url', 'topic_url', 'version', 'last_update'];
        if (Auth::user()->isAdmin()) {
            $toUpdate = ['sensibility', 'autor', 'description', 'js_url', 'repo_url', 'photo_url', 'don_url', 'website_url', 'topic_url', 'user_id', 'version', 'last_update'];
            if ($request->input('user_id') == '') {
                $request->merge(['user_id' => null]);
            } else {
                //force username of owner 
                $request->merge(['autor' => User::find($request->input('user_id'))->name]);
            }
        }

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else {
            $skin->fill($request->only($toUpdate));
            $skin->version = $request->input('version');
            if ($request->has('last_update')) {
                $skin->last_update = \Carbon\Carbon::createFromFormat('d/m/Y', $request->input('last_update'));
            }
            $skin->save();
            return redirect(route('skin.show', ['slug' => $slug]));
        }
    }

    public function validateScript($slug) {
        $script = Script::where('slug', $slug)->firstOrFail();
        $this->lib->adminOrFail();

        if ($script->status != 1) {
            $script->status = 1;
            $script->save();
            if ($script->user_id != null) {
//                Mail::to($script->poster_user()->first()->email)->send(new Notify($script));
                $script->poster_user()->first()->notify(new notifyStatus($script));
            }
        }
        return redirect(route('script.show', ['slug' => $slug]));
    }

    public function validateSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();
        $this->lib->adminOrFail();

        if ($skin->status != 1) {
            $skin->status = 1;
            $skin->save();
            if ($skin->user_id != null) {
//                Mail::to($skin->poster_user()->first()->email)->send(new Notify($skin));
                $skin->poster_user()->first()->notify(new notifyStatus($skin));
            }
        }
        return redirect(route('skin.show', ['slug' => $slug]));
    }

    public function refuseScript($slug) {
        $script = Script::where('slug', $slug)->firstOrFail();
        $this->lib->adminOrFail();

        if ($script->status != 2) {
            $script->status = 2;
            $script->save();
            if ($script->user_id != null) {
//                Mail::to($script->poster_user()->first()->email)->send(new Notify($script));
                $script->poster_user()->first()->notify(new notifyStatus($script));
            }
        }
        return redirect(route('script.show', ['slug' => $slug]));
    }

    public function refuseSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();
        $this->lib->adminOrFail();

        if ($skin->status != 2) {
            $skin->status = 2;
            $skin->save();
            if ($skin->user_id != null) {
//                Mail::to($skin->poster_user()->first()->email)->send(new Notify($skin));
                $skin->poster_user()->first()->notify(new notifyStatus($skin));
            }
        }
        return redirect(route('skin.show', ['slug' => $slug]));
    }

    /**
     * Install script : count & redirect 
     */
    public function installScript($slug, Request $request) {
        $script = Script::where('slug', $slug)->firstOrFail();

        // protection referer to count
        if (str_contains($request->headers->get('referer'), $slug)) {
            $history = History::where(['ip' => $request->ip(), 'what' => $slug, 'action' => 'install']);
            if ($history->count() == 0) {
                History::create(['ip' => $request->ip(), 'what' => $slug, 'action' => 'install']);
                $script->install_count++;
                $script->save();
            }
        }
        return redirect($script->js_url);
    }

    /**
     * Note script : note & redirect 
     */
    public function noteScript($slug, $note, Request $request) {
        $note = intval($note);
        if ($note > 0 && $note <= 5) {
            $script = Script::where('slug', $slug)->firstOrFail();
            //if no history note_count +1
            $history = History::where(['ip' => $request->ip(), 'what' => "script_$slug", 'action' => 'note']);
            if ($history->count() == 0) {
                History::create(['ip' => $request->ip(), 'what' => "script_$slug", 'action' => 'note']);
                $script->note = ( $script->note * $script->note_count + $note ) / ($script->note_count + 1);
                $script->note_count++;
                $script->save();
            }
        }
        return redirect(route('script.show', $slug));
    }

    /**
     * Install script : count & redirect 
     */
    public function installSkin($slug, Request $request) {
        $skin = Skin::where('slug', $slug)->firstOrFail();

        //if no history install_count +1
        // protection referer to count
        if (str_contains($request->headers->get('referer'), $slug)) {
            $history = History::where(['ip' => $request->ip(), 'what' => "skin_$slug", 'action' => 'install']);
            if ($history->count() == 0) {
                History::create(['ip' => $request->ip(), 'what' => "skin_$slug", 'action' => 'install']);
                $skin->install_count++;
                $skin->save();
            }
        }
        return redirect($skin->skin_url);
    }

    /**
     * Note script : note & redirect 
     */
    public function noteSkin($slug, $note, Request $request) {
        $note = intval($note);
        if ($note > 0 && $note <= 5) {
            $skin = Skin::where('slug', $slug)->firstOrFail();
            //if no history note_count +1
            $history = History::where(['ip' => $request->ip(), 'what' => "skin_$slug", 'action' => 'note']);
            if ($history->count() == 0) {
                History::create(['ip' => $request->ip(), 'what' => "skin_$slug", 'action' => 'note']);
                $skin->note = ( $skin->note * $skin->note_count + $note ) / ($skin->note_count + 1);
                $skin->note_count++;
                $skin->save();
            }
        }
        return redirect(route('skin.show', $slug));
    }

    /**
     * ============
     * Some Views bellow 
     * ============
     */
    public function formScript() {
        return view('script.form');
    }

    public function formSkin() {
        return view('skin.form');
    }

    public function showScript($slug) {
        $script = Script::where('slug', $slug)->firstOrFail();
        $comments = $script->comments()->orderBy('created_at', 'desc')->paginate(10);
        //affiche les non validés seulement si admin
        if (!$script->isValidated() && !(Auth::check() && Auth::user()->isAdmin())) {
            abort(404);
        }
        $Parsedown = new \Parsedown();
        $Parsedown->setMarkupEscaped(true);
        $script->description = $Parsedown->text($script->description);

        return view('script.show', ['script' => $script, 'comments' => $comments, 'show_captcha' => $this->limitComment($this->min_time_captcha)]);
    }

    public function showSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();
        $comments = $skin->comments()->orderBy('created_at', 'desc')->paginate(10);
        //affiche les non validés seulement si admin
        if (!$skin->isValidated() && !(Auth::check() && Auth::user()->isAdmin())) {
            abort(404);
        }
        $Parsedown = new \Parsedown();
        $Parsedown->setMarkupEscaped(true);
        $skin->description = $Parsedown->text($skin->description);

        return view('skin.show', ['skin' => $skin, 'comments' => $comments, 'show_captcha' => $this->limitComment($this->min_time_captcha)]);
    }

    public function editScript($slug) {
        $script = Script::where('slug', $slug)->firstOrFail();
        $this->lib->ownerOradminOrFail($script->user_id);
        return view('script.edit', ['script' => $script]);
    }

    public function editSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();
        $this->lib->ownerOradminOrFail($skin->user_id);
        return view('skin.edit', ['skin' => $skin]);
    }

    public function deleteScript($slug) {
        $script = Script::where('slug', $slug)->firstOrFail();
        $this->lib->ownerOradminOrFail($script->user_id);
        $script->comments()->delete();
        $script->delete();
        $message = "[delete script] Script supprimé par " . Auth::user()->name . " : $script->name | $script->slug ";
        $this->lib->sendDiscord($message, $this->discord_url);
        if (Auth::user()->isAdmin())
            return redirect(route('admin_index'));
        return redirect(route('index'));
    }

    public function deleteSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();
        $this->lib->ownerOradminOrFail($skin->user_id);
        $skin->comments()->delete();
        $skin->delete();
        $message = "[delete script] Skin supprimé par " . Auth::user()->name . " : $skin->name | $skin->slug ";
        $this->lib->sendDiscord($message, $this->discord_url);

        if (Auth::user()->isAdmin())
            return redirect(route('admin_index'));
        return redirect(route('index'));
    }

    public function slugifyScript($name) {
        $slug = $this->lib->slugify($name);
        $i = 1;
        $baseSlug = $slug;
        while (Script::where('slug', $slug)->count() > 0) {
            $slug = $baseSlug . "-" . $i++;
        }
        return $slug;
    }

    public function slugifySkin($name) {
        $slug = $this->lib->slugify($name);
        $i = 1;
        $baseSlug = $slug;
        while (Skin::where('slug', $slug)->count() > 0) {
            $slug = $baseSlug . "-" . $i++;
        }
        return $slug;
    }

    public function crawlInfo() {
        $this->lib->adminOrFail();
        $this->lib->crawlInfo();
    }

}
