package dk.mitspor.app;

import android.Manifest;
import android.content.Context;
import android.hardware.Sensor;
import android.hardware.SensorEvent;
import android.hardware.SensorEventListener;
import android.hardware.SensorManager;
import android.os.Build;
import android.os.SystemClock;
import com.getcapacitor.JSObject;
import com.getcapacitor.PermissionState;
import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;
import com.getcapacitor.annotation.Permission;
import com.getcapacitor.annotation.PermissionCallback;

/**
 * Telefonens skridttæller (Sensor.TYPE_STEP_COUNTER) til optageren (skridt.js).
 *
 * Tælleren lytter videre, når appen er i baggrunden eller skærmen er slukket.
 * Det gør færdige plugins ikke, men under en optagelse holder GPS'ens
 * foreground service appen i live, og skridtene skal med hele turen.
 *
 * Hændelsen "skridt" sender { antal, tid }: antal er telefonens samlede
 * skridt siden genstart (JavaScript regner forskellen ud), og tid er
 * tidspunktet for målingen i ms siden 1970. Sensoren kan samle målinger op
 * og aflevere dem samlet, så tiden tages fra målingen, ikke fra nu.
 */
@CapacitorPlugin(
    name = "MitSporSkridt",
    permissions = { @Permission(strings = { Manifest.permission.ACTIVITY_RECOGNITION }, alias = "aktivitet") }
)
public class SkridtPlugin extends Plugin implements SensorEventListener {

    private SensorManager sensorManager;
    private Sensor taeller;
    private boolean lytter = false;

    @Override
    public void load() {
        sensorManager = (SensorManager) getContext().getSystemService(Context.SENSOR_SERVICE);
        if (sensorManager != null) {
            taeller = sensorManager.getDefaultSensor(Sensor.TYPE_STEP_COUNTER);
        }
    }

    /** Har telefonen en skridttæller? */
    @PluginMethod
    public void findes(PluginCall call) {
        JSObject svar = new JSObject();
        svar.put("findes", taeller != null);
        call.resolve(svar);
    }

    // Før Android 10 kræver skridttælleren ingen tilladelse.
    private boolean tilladt() {
        return Build.VERSION.SDK_INT < Build.VERSION_CODES.Q || getPermissionState("aktivitet") == PermissionState.GRANTED;
    }

    private void svarTilladelse(PluginCall call) {
        JSObject svar = new JSObject();
        svar.put("tilladt", tilladt());
        call.resolve(svar);
    }

    @PluginMethod
    public void tilladelse(PluginCall call) {
        svarTilladelse(call);
    }

    /** Beder om tilladelsen "Fysisk aktivitet", hvis den mangler. */
    @PluginMethod
    public void bedOmTilladelse(PluginCall call) {
        if (tilladt()) {
            svarTilladelse(call);
        } else {
            requestPermissionForAlias("aktivitet", call, "tilladelseSvar");
        }
    }

    @PermissionCallback
    private void tilladelseSvar(PluginCall call) {
        svarTilladelse(call);
    }

    @PluginMethod
    public void start(PluginCall call) {
        if (taeller == null) {
            call.reject("Telefonen har ingen skridttæller.");
            return;
        }
        if (!tilladt()) {
            call.reject("MitSpor må ikke bruge fysisk aktivitet.");
            return;
        }
        if (!lytter) {
            lytter = sensorManager.registerListener(this, taeller, SensorManager.SENSOR_DELAY_NORMAL);
        }
        if (lytter) {
            call.resolve();
        } else {
            call.reject("Skridttælleren kunne ikke startes.");
        }
    }

    @PluginMethod
    public void stop(PluginCall call) {
        stopLytter();
        call.resolve();
    }

    private void stopLytter() {
        if (lytter && sensorManager != null) {
            sensorManager.unregisterListener(this);
        }
        lytter = false;
    }

    @Override
    protected void handleOnDestroy() {
        stopLytter();
        super.handleOnDestroy();
    }

    @Override
    public void onSensorChanged(SensorEvent event) {
        if (event.sensor.getType() != Sensor.TYPE_STEP_COUNTER) {
            return;
        }
        // event.timestamp er ns siden opstart; omregnes til ur-tid.
        long alderMs = (SystemClock.elapsedRealtimeNanos() - event.timestamp) / 1000000L;
        JSObject data = new JSObject();
        data.put("antal", (long) event.values[0]);
        data.put("tid", System.currentTimeMillis() - Math.max(0L, alderMs));
        notifyListeners("skridt", data);
    }

    @Override
    public void onAccuracyChanged(Sensor sensor, int accuracy) {
        // Bruges ikke.
    }
}
