<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Script,
    App\Skin,
    App\History;
use Validator;
use Illuminate\Support\Facades\Mail;
use App\Mail\Contact;
use Auth;

class JvscriptController extends Controller {

    //_TODO : retenir le filtre/sort en session/cookie utilisateur 
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct() {
//        $this->middleware('auth');
        $this->recaptcha_key = env('RECAPTCHA_KEY', '');
        $this->discord_url = env('DISCORD_URL', '');
    }

    public function index(Request $request) {
        $scripts = Script::where("status", "1");
        $skins = Skin::where("status", "1");

        $collection = collect([$scripts, $skins]);
        $collapsed = $collection->collapse();
        $scripts = $collapsed->all(); //
        $scripts = $collapsed->sortByDesc('note');

        return view('index', ['scripts' => $scripts]);
    }

    /**
     * Store a script in db
     */
    public function storeScript(Request $request) {
        // $user = Auth::user();
        $validator = Validator::make($request->all(), [
                    'name' => 'required|max:255|unique:scripts',
                    'js_url' => "required|url",
                    'repo_url' => "url",
                    'photo_url' => "url",
                    'don_url' => "url",
                    "user_email" => "email"
        ]);

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else { //sucess > insert  
            //captcha validation
            $recaptcha = new \ReCaptcha\ReCaptcha($this->recaptcha_key);
            $resp = $recaptcha->verify($request->input('g-recaptcha-response'), $_SERVER['REMOTE_ADDR']);
            if (!$resp->isSuccess()) {
                $request->flash();
                return redirect(route('script.form'))->withErrors(['recaptcha' => 'Veuillez valider le captcha svp.']);
            }

            $script = Script::create($request->all());
            $slug = $this->slugify($script->name);
            $i = 1;
            $baseSlug = $slug;
            while ($this->slugExistScript($slug)) {
                $slug = $baseSlug . "-" . $i++;
            }
            $script->slug = $slug;
            $script->save();

            $message = "[new script] Nouveau script posté sur le site : " . route('script.show', ['slug' => $script->slug]);
            $this->sendDiscord($message, $this->discord_url);

            return redirect(route('script.form'))->with("message", "Merci, votre script est en attente de validation.");
        }
    }

    public function updateScript(Request $request, $slug) {
//       _todo protection admin

        $validator = Validator::make($request->all(), [
                    'js_url' => "required|url",
                    'repo_url' => "url",
                    'photo_url' => "url",
                    'don_url' => "url",
                    "user_email" => "email"
        ]);
        //update only this fields
        $toUpdate = ['sensibility', 'autor', 'description', 'js_url', 'repo_url', 'photo_url', 'don_url', 'user_email'];

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else {
            $script = Script::where('slug', $slug)->firstOrFail();
            $script->fill($request->only($toUpdate));
            $script->save();
            return redirect(route('script.show', ['slug' => $slug]));
        }
    }

    /**
     * Store a skin in db
     */
    public function storeSkin(Request $request) {
//        $user = Auth::user();
        $validator = Validator::make($request->all(), [
                    'name' => 'required|max:255|unique:skins',
                    'skin_url' => "required|url",
                    'repo_url' => "url",
                    'photo_url' => "url",
                    'don_url' => "url",
                    "user_email" => "email"
        ]);

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else { //sucess > insert  
            //captcha validation
            $recaptcha = new \ReCaptcha\ReCaptcha($this->recaptcha_key);
            $resp = $recaptcha->verify($request->input('g-recaptcha-response'), $_SERVER['REMOTE_ADDR']);
            if (!$resp->isSuccess()) {
                $request->flash();
                return redirect(route('skin.form'))->withErrors(['recaptcha' => 'Veuillez valider le captcha svp.']);
            }

            $script = Skin::create($request->all());
            $slug = $this->slugify($script->name);
            $i = 1;
            $baseSlug = $slug;
            while ($this->slugExistSkin($slug)) {
                $slug = $baseSlug . "-" . $i++;
            }
            $script->slug = $slug;
            $script->save();

            $message = "[new skin] Nouveau skin posté sur le site : " . route('skin.show', ['slug' => $script->slug]);
            $this->sendDiscord($message, $this->discord_url);

            return redirect(route('skin.form'))->with("message", "Merci, votre skin est en attente de validation.");
        }
    }

    /**
     * Install script : count & redirect 
     */
    public function installScript($slug) {
        $script = Script::where('slug', $slug)->first();
        if (!$script) {
            abort(404);
        }
        //if no history install_count +1
        $history = History::where(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => $slug, 'action' => 'install']);
        if ($history->count() == 0) {
            History::create(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => $slug, 'action' => 'install']);
            $script->install_count++;
            $script->save();
        }
        return redirect($script->js_url);
    }

    /**
     * Note script : note & redirect 
     */
    public function noteScript($slug, $note) {
        $note = intval($note);
        if ($note > 0 && $note <= 5) {
            $script = Script::where('slug', $slug)->first();
            if (!$script) {
                abort(404);
            }
            //if no history note_count +1
            $history = History::where(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => "script_$slug", 'action' => 'note']);
            if ($history->count() == 0) {
                History::create(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => "script_$slug", 'action' => 'note']);
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
    public function installSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();

        //if no history install_count +1
        $history = History::where(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => "skin_$slug", 'action' => 'install']);
        if ($history->count() == 0) {
            History::create(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => "skin_$slug", 'action' => 'install']);
            $skin->install_count++;
            $skin->save();
        }
        return redirect($skin->skin_url);
    }

    /**
     * Note script : note & redirect 
     */
    public function noteSkin($slug, $note) {
        $note = intval($note);
        if ($note > 0 && $note <= 5) {
            $skin = Skin::where('slug', $slug)->first();
            if (!$skin) {
                abort(404);
            }
            //if no history note_count +1
            $history = History::where(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => "skin_$slug", 'action' => 'note']);
            if ($history->count() == 0) {
                History::create(['ip' => $_SERVER['REMOTE_ADDR'], 'what' => "skin_$slug", 'action' => 'note']);
                $skin->note = ( $skin->note * $skin->note_count + $note ) / ($skin->note_count + 1);
                $skin->note_count++;
                $skin->save();
            }
        }
        return redirect(route('skin.show', $slug));
    }

    /**
     * Contact send (discord bot)
     */
    public function contactSend(Request $request) {
        $validator = Validator::make($request->all(), [
                    'email' => 'email',
                    'message_body' => "required"
        ]);

        if ($validator->fails()) {
            $this->throwValidationException(
                    $request, $validator
            );
        } else {
            //captcha validation
            $recaptcha = new \ReCaptcha\ReCaptcha($this->recaptcha_key);
            $resp = $recaptcha->verify($request->input('g-recaptcha-response'), $_SERVER['REMOTE_ADDR']);
            if (!$resp->isSuccess()) {
                $request->flash();
                return redirect(route('contact.form'))->withErrors(['recaptcha' => 'Veuillez valider le captcha svp.']);
            }

            //send discord 
            $this->discord_url;
            $message = "[contact form] ";
            if ($request->input('email')) {
                $message .= "Email : " . $request->input('email') . '.';
            }
            $message .= "Message : " . $request->input('message_body');
            $this->sendDiscord($message, $this->discord_url);

//            Mail::to(env('ADMIN_EMAIL', 'contact@jvscript.io'))->send(new Contact($request->input('email'), $request->input('message_body')));

            return redirect(route('contact.form'))->with("message", "Merci, votre message a été envoyé.");
        }

        return redirect(route('contact.form'));
    }

    /**
     * ============
     * Views bellow 
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

        //affiche les non validés seulement si admin
        if (!$script->isValidated() && !(Auth::check() && Auth::user()->isAdmin())) {
            abort(404);
        }

        return view('script.show', ['script' => $script]);
    }

    public function showSkin($slug) {
        $skin = Skin::where('slug', $slug)->firstOrFail();

        //affiche les non validés seulement si admin
        if (!$skin->isValidated() && !(Auth::check() && Auth::user()->isAdmin())) {
            abort(404);
        }

        return view('skin.show', ['skin' => $skin]);
    }

    public function editScript($slug) {
        if (!(Auth::check() && Auth::user()->isAdmin()))
            abort(404);

        $script = Script::where('slug', $slug)->firstOrFail();
        return view('script.edit', ['script' => $script]);
    }

    public function editSkin($slug) {
        if (!(Auth::check() && Auth::user()->isAdmin()))
            abort(404);

        $script = Script::where('slug', $slug)->firstOrFail();
        return view('script.edit', ['script' => $script]);
    }

    public function slugExistScript($slug) {
        return Script::where('slug', $slug)->count() > 0;
    }

    public function slugExistSkin($slug) {
        return Skin::where('slug', $slug)->count() > 0;
    }

    static public function slugify($text) {
        // replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        // transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        // remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);
        // trim
        $text = trim($text, '-');
        // remove duplicate -
        $text = preg_replace('~-+~', '-', $text);
        // lowercase
        $text = strtolower($text);
        if (empty($text)) {
            return 'n-a';
        }
        return $text;
    }

    public function sendDiscord($content, $url) {
        if (empty($content)) {
            throw new NoContentException('No content provided');
        }
        if (empty($url)) {
            throw new NoURLException('No URL provided');
        }
        $data = array("content" => $content);
        $data_string = json_encode($data);
        $opts = array(
            'http' => array(
                'method' => "POST",
                "name" => "jvscript.io",
                "user_name" => "jvscript.io",
                'header' => "Content-Type: application/json\r\n",
                'content' => $data_string
            )
        );

        $context = stream_context_create($opts);
        file_get_contents($url, false, $context);
    }

}
