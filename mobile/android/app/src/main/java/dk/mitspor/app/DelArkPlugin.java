package dk.mitspor.app;

import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.Intent;
import android.net.Uri;
import android.webkit.MimeTypeMap;
import androidx.core.content.FileProvider;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import java.io.File;
import java.io.IOException;

/**
 * Del-ark til filer, der er hentet fra sitet (hent.js).
 *
 * @capacitor/share venter på svaret fra del-arket og afviser et nyt del-ark,
 * indtil svaret er kommet. Android afleverer først svaret, når appens eget
 * vindue er forrest igen, og mens sitet er åbent i WebView'et, ligger det
 * forrest. Derfor virkede kun den første download. Her åbnes del-arket
 * uden at vente på svar, så det kan åbnes igen og igen.
 */
@CapacitorPlugin(name = "MitSporDelArk")
public class DelArkPlugin extends Plugin {

    @PluginMethod
    public void del(PluginCall call) {
        String sti = call.getString("fil");
        String titel = call.getString("titel", "Gem eller del filen");
        if (sti == null || sti.isEmpty()) {
            call.reject("Ingen fil");
            return;
        }

        File fil;
        try {
            Uri uri = Uri.parse(sti);
            fil = new File(uri.getPath() != null ? uri.getPath() : sti).getCanonicalFile();
            // Kun filer i appens cache (der gemmer hent.js filerne).
            String cache = getContext().getCacheDir().getCanonicalPath() + File.separator;
            if (!fil.getPath().startsWith(cache) || !fil.isFile()) {
                call.reject("Filen findes ikke");
                return;
            }
        } catch (IOException e) {
            call.reject("Filen findes ikke");
            return;
        }

        Uri indhold;
        try {
            indhold = FileProvider.getUriForFile(getContext(), getContext().getPackageName() + ".fileprovider", fil);
        } catch (IllegalArgumentException e) {
            call.reject("Filen kan ikke deles");
            return;
        }

        Intent send = new Intent(Intent.ACTION_SEND);
        send.setType(mimeType(fil.getName()));
        send.putExtra(Intent.EXTRA_STREAM, indhold);
        send.putExtra(Intent.EXTRA_TITLE, fil.getName());
        // ClipData sørger for, at læseadgangen følger med gennem del-arket.
        send.setClipData(ClipData.newRawUri(fil.getName(), indhold));
        send.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);

        Intent arket = Intent.createChooser(send, titel);
        arket.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);

        try {
            getActivity().startActivity(arket);
        } catch (ActivityNotFoundException e) {
            call.reject("Ingen app kan modtage filen");
            return;
        }
        call.resolve();
    }

    private static String mimeType(String navn) {
        String lille = navn.toLowerCase();
        if (lille.endsWith(".gpx")) {
            return "application/gpx+xml";
        }
        if (lille.endsWith(".zip")) {
            return "application/zip";
        }
        int punkt = lille.lastIndexOf('.');
        String type = punkt >= 0 ? MimeTypeMap.getSingleton().getMimeTypeFromExtension(lille.substring(punkt + 1)) : null;
        return type != null ? type : "application/octet-stream";
    }
}
