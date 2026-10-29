# Add project specific ProGuard rules here.
# You can control the set of applied configuration files using the
# proguardFiles setting in build.gradle.
#
# For more details, see
#   http://developer.android.com/guide/developing/tools/proguard.html

# If your project uses WebView with JS, uncomment the following
# and specify the fully qualified class name to the JavaScript interface
# class:
#-keepclassmembers class fqcn.of.javascript.interface.for.webview {
#   public *;
#}

# Uncomment this to preserve the line number information for
# debugging stack traces.
#-keepattributes SourceFile,LineNumberTable

# If you keep the line number information, uncomment this to
# hide the original source file name.
#-renamesourcefileattribute SourceFile

# MitSpor: behold linjenumre, så nedbrud i Google Play kan læses (mapping-filen ligger i AAB'en)
-keepattributes SourceFile,LineNumberTable
-renamesourcefileattribute SourceFile

# InAppBrowser læser sine indstillinger med Gson via refleksion
-keepattributes Signature,*Annotation*
-keep class com.capacitorjs.osinappbrowser.** { *; }
-keep class com.outsystems.plugins.inappbrowser.** { *; }

# Capacitor læser @CapacitorPlugin (pluginnets tilladelser) og @PluginMethod via refleksion.
# Uden disse regler fjerner R8 annotationerne i release, og fx GPS-optageren hænger, fordi
# pluginnet tror, at placering ikke er tilladt, og beder om en tom liste af tilladelser.
-keep @interface com.getcapacitor.annotation.** { *; }
-keep @interface com.getcapacitor.PluginMethod { *; }
-keep @interface com.getcapacitor.NativePlugin { *; }
