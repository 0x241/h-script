export default {
  content: [
    "./tpl/**/*.{twig,tpl}",
    "./module/**/*.php",
    "./static/js/**/*.js",
    "./rw.php"
  ],
  darkMode: "class",
  theme: {
    extend: {
      colors: {
        brand: "#1A1A1A",
        bgLight: "#F7F7F5",
        cardBlue: "#E3EDFF",
        cardGreen: "#E2F7EB",
        cardPurple: "#F0E5FF",
        cardPeach: "#FFE8DF",
        cardGray: "#F0F0F0",
        textDim: "#5C5C5C",
        primary: "hsl(var(--primary) / <alpha-value>)",
        accent: "hsl(var(--accent) / <alpha-value>)",
        surface: "var(--color-surface)",
        muted: "var(--color-muted)",
        danger: "var(--color-danger)",
        success: "var(--color-success)"
      },
      fontFamily: {
        sans: ["Inter", "system-ui", "sans-serif"],
        mono: ["JetBrains Mono", "ui-monospace", "SFMono-Regular", "Menlo", "monospace"]
      },
      boxShadow: {
        glass: "0 20px 70px rgba(0, 0, 0, 0.28)",
        soft: "0 12px 36px rgba(0, 0, 0, 0.18)"
      },
      keyframes: {
        "hero-type": {
          "0%": { opacity: "1", width: "0" },
          "8%, 28%": { opacity: "1", width: "var(--hero-width)" },
          "32%": { opacity: "1", width: "0" },
          "33%, 100%": { opacity: "0", width: "0" }
        },
        "hero-slot": {
          "0%, 32.99%": { width: "4.8em" },
          "33%, 65.99%": { width: "5em" },
          "66%, 100%": { width: "6.6em" }
        }
      },
      animation: {
        "hero-type-9": "hero-type 13s steps(9, end) infinite",
        "hero-type-10": "hero-type 13s steps(10, end) infinite",
        "hero-type-13": "hero-type 13s steps(13, end) infinite",
        "hero-slot": "hero-slot 13s linear infinite"
      },
      borderRadius: {
        card: "8px"
      }
    }
  }
};
