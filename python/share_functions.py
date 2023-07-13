import yfinance as yf
from functions import insert, query

def insert_statistic (symbol,exchange,indicator,date,value):
    try:
            
        rows=query("select symbol from statistics where symbol='"+symbol+"' and exchange='"+exchange+"' and indicator='"+indicator+"'and date='"+date+"'")
    
        if (rows):
            query("update statistics set value='"+str(value)+"' where symbol='"+symbol+"' and exchange='"+exchange+"' and indicator='"+indicator+"'and date='"+date+"'")
			
        else:
            insert("insert into statistics (symbol, exchange, indicator, value,date) values ('"+symbol+"','"+exchange+"','"+indicator+"','"+str(value)+"','"+date+"')")
            

    except Exception as error:
        print("Error in insert_statistic:"+str(error))
        return None



def get_key_ratios(session,exchange, symbol):
    try:
        ticker = yf.Ticker(symbol,session=session)
        info = ticker.info

        key_ratios = {}  # Empty dictionary
        
        print (info.keys())

        for key in info.keys():
            #print ("key="+key)
            #print  ("key_ratio="+str(info[key]) )
            # Adding elements to the dictionary by assigning values to keys
            key_ratios[key] = info[key]

        return key_ratios
            
    except Exception as error:
        print("Error in get_key_ratios:"+str(error))
        return None

