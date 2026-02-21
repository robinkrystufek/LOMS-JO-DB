CookieConsent.run({
    guiOptions: {
        consentModal: {
            layout: "cloud inline",
            position: "bottom center",
            equalWeightButtons: true,
            flipButtons: false
        },
        preferencesModal: {
            layout: "box",
            position: "right",
            equalWeightButtons: true,
            flipButtons: false
        }
    },
    categories: {
        necessary: {
            readOnly: true
        },
        functionality: {}
    },
    language: {
        default: "en",
        translations: {
            en: {
                consentModal: {
                    title: "Welcome to the LOMS Judd–Ofelt analysis suite!",
                    description: "<p style='text-align: justify; display: inline-block; font-size: small;'>We use cookies to enhance your browsing experience and analyze website traffic. By clicking 'Accept all', you consent to the use of all cookies. You can manage your preferences by clicking 'Manage preferences'.</p><p style='text-align: justify; display: inline-block; font-size: small;'>Works published using this tool shall reference: J.Hrabovsky, P. Varak, R. Krystufek, LOMS: interactive online software for Classical and Combinatorial Judd-Ofelt analysis with integrated database of Judd-Ofelt parameters, preprint, 8. August 2024.</p>",
                    acceptAllBtn: "Accept all",
                    acceptNecessaryBtn: "Reject all",
                    showPreferencesBtn: "Manage preferences",
                    footer: "<a href=\"https://www.loms.cz/privacy-policy/\">Privacy Policy</a>\n<a href=\"https://www.loms.cz/about/#TC\">Terms and conditions</a>"
                },
                preferencesModal: {
                    title: "Consent Preferences Center",
                    acceptAllBtn: "Accept all",
                    acceptNecessaryBtn: "Reject all",
                    savePreferencesBtn: "Save preferences",
                    closeIconLabel: "Close modal",
                    serviceCounterLabel: "Service|Services",
                    sections: [
                        {
                            title: "Cookie Usage",
                            description: "This website uses cookies to ensure you get the best experience on our website. By clicking 'Accept all', you consent to the use of all cookies. You can manage your preferences by clicking 'Manage preferences'."
                        },
                        {
                            title: "Strictly Necessary Cookies <span class=\"pm__badge\">Always Enabled</span>",
                            description: "These cookies are essential for the website to function properly. They enable basic functions like page navigation and access to secure areas of the website. Without these cookies, the website cannot function properly.",
                            linkedCategory: "necessary"
                        },
                        {
                            title: "Functionality Cookies",
                            description: "These cookies are used to enhance the functionality of the website. They may be set by us or by third-party providers whose services we have added to our pages. If you do not allow these cookies, then some or all of these services may not function properly.",
                            linkedCategory: "functionality"
                        },
                        {
                            title: "More information",
                            description: "For any query in relation to our policy on cookies and your choices, please <a class=\"cc__link\" href=\"https://www.loms.cz/contact/\">contact us</a>."
                        }
                    ]
                }
            }
        }
    }
});